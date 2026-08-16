<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\FamilyRelationship;
use App\Models\Space;
use App\Policies\SpacePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FamilyController — árvore genealógica do espaço familiar.
 * Fase 1, entregas 1.1 e 1.2 do PLANO_TRILHAS_2026-08.md (T1-R01, T1-R02).
 *
 * ENDPOINTS
 *   GET    /spaces/{space}/family            árvore completa (nós + arestas)
 *   POST   /spaces/{space}/family            cria vínculo
 *   PUT    /spaces/{space}/family/{id}       atualiza vínculo
 *   DELETE /spaces/{space}/family/{id}       remove vínculo
 *
 * MODELO
 * A árvore é um grafo: nós são as entidades do espaço (pessoas e pets),
 * arestas são os vínculos tipados. O frontend desenha; o backend garante
 * consistência.
 *
 * O QUE ESTE CONTROLLER IMPEDE
 *   - vínculo com entidade de outro espaço (vazamento entre famílias)
 *   - auto-relacionamento (alguém pai de si mesmo)
 *   - duplicata do mesmo vínculo (unique no banco + verificação amigável)
 *   - ciclo em relação vertical (A pai de B, B pai de A)
 *
 * O QUE ELE NÃO IMPEDE, DE PROPÓSITO
 * Configurações incomuns mas reais: pessoa com dois responsáveis legais,
 * segundos casamentos, guarda compartilhada. Família real não cabe em
 * regra rígida, e um sistema que recusa a família do usuário é pior que um
 * que aceita um dado estranho.
 */
class FamilyController extends Controller
{
    /**
     * GET /spaces/{space}/family
     *
     * Devolve nós e arestas. As arestas vêm com o inverso já calculado,
     * para o frontend não precisar conhecer a tabela de inversos.
     */
    public function index(Request $request, $spaceId)
    {
        $space = Space::find($spaceId);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'entity.view');

        $entities = Entity::where('space_id', $space->id)
            ->orderBy('id')
            ->get();

        $relationships = FamilyRelationship::where('space_id', $space->id)->get();

        return response()->json([
            'space' => [
                'id'   => $space->id,
                'name' => $space->name,
                'type' => $space->type,
            ],
            // Sem limite de perfis por conta (T1-R01).
            'nodes' => $entities->map(fn (Entity $e) => [
                'entity_id'   => $e->id,
                'unique_code' => $e->unique_code,
                'type'        => $e->type,
                'name'        => $e->encrypted_name,
                'status'      => $e->status,
            ])->values(),

            'edges' => $relationships->map(fn (FamilyRelationship $r) => [
                'id'             => $r->id,
                'from_entity_id' => $r->from_entity_id,
                'to_entity_id'   => $r->to_entity_id,
                'relation_type'  => $r->relation_type,
                'label'          => FamilyRelationship::labelOf($r->relation_type),
                'is_symmetric'   => $r->is_symmetric,
                // Como ler a aresta no sentido contrário.
                'inverse_type'   => FamilyRelationship::inverseOf($r->relation_type),
                'inverse_label'  => FamilyRelationship::inverseOf($r->relation_type)
                    ? FamilyRelationship::labelOf(FamilyRelationship::inverseOf($r->relation_type))
                    : null,
                'note'           => $r->note,
            ])->values(),

            // Dicionário para o frontend montar o seletor sem duplicar dados.
            'relation_types' => collect(FamilyRelationship::TYPES)
                ->map(fn (string $t) => [
                    'value'        => $t,
                    'label'        => FamilyRelationship::labelOf($t),
                    'is_symmetric' => FamilyRelationship::isSymmetric($t),
                ])
                ->values(),
        ]);
    }

    /**
     * POST /spaces/{space}/family
     * Body: from_entity_id, to_entity_id, relation_type, note?
     */
    public function store(Request $request, $spaceId)
    {
        $space = Space::find($spaceId);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        // Editar a árvore é gestão de familiares — exige `entity.edit`, que
        // o fundador pode delegar a outro membro (T1-R04).
        app(SpacePolicy::class)->authorize($request->tenant, $space, 'entity.edit');

        $validated = $request->validate([
            'from_entity_id' => 'required|integer',
            'to_entity_id'   => 'required|integer',
            'relation_type'  => 'required|string|in:' . implode(',', FamilyRelationship::TYPES),
            'note'           => 'sometimes|nullable|string|max:255',
        ]);

        if ($validated['from_entity_id'] === $validated['to_entity_id']) {
            return response()->json([
                'error' => 'Uma pessoa não pode ter vínculo de parentesco consigo mesma.',
                'code'  => 'SELF_RELATION',
            ], 422);
        }

        // Ambas as pontas precisam estar NESTE espaço: sem isso seria
        // possível ligar a árvore a uma entidade de outra família e ler
        // dados de fora pelo grafo.
        $entities = Entity::whereIn('id', [$validated['from_entity_id'], $validated['to_entity_id']])
            ->where('space_id', $space->id)
            ->pluck('id');

        if ($entities->count() !== 2) {
            return response()->json([
                'error' => 'Ambos os perfis precisam pertencer a este espaço familiar.',
                'code'  => 'ENTITY_NOT_IN_SPACE',
            ], 422);
        }

        $type = $validated['relation_type'];
        $isSymmetric = FamilyRelationship::isSymmetric($type);

        // Duplicata: o unique do banco já barra, mas a mensagem dele não
        // serve para o usuário.
        if ($this->relationExists($space->id, $validated['from_entity_id'], $validated['to_entity_id'], $type, $isSymmetric)) {
            return response()->json([
                'error' => 'Este vínculo já está cadastrado.',
                'code'  => 'DUPLICATE_RELATION',
            ], 422);
        }

        // Ciclo vertical: A pai de B e B pai de A não existe no mundo real
        // e quebraria qualquer desenho de árvore.
        if (!$isSymmetric && $this->wouldCreateVerticalCycle($space->id, $validated['from_entity_id'], $validated['to_entity_id'], $type)) {
            return response()->json([
                'error' => 'Este vínculo criaria um ciclo impossível na árvore (a mesma pessoa como ascendente e descendente).',
                'code'  => 'CYCLE_DETECTED',
            ], 422);
        }

        $relationship = DB::transaction(fn () => FamilyRelationship::create([
            'space_id'             => $space->id,
            'from_entity_id'       => $validated['from_entity_id'],
            'to_entity_id'         => $validated['to_entity_id'],
            'relation_type'        => $type,
            'is_symmetric'         => $isSymmetric,
            'note'                 => $validated['note'] ?? null,
            'created_by_tenant_id' => $request->tenant->id,
        ]));

        return response()->json([
            'message'      => 'Vínculo criado.',
            'relationship' => [
                'id'             => $relationship->id,
                'from_entity_id' => $relationship->from_entity_id,
                'to_entity_id'   => $relationship->to_entity_id,
                'relation_type'  => $relationship->relation_type,
                'label'          => FamilyRelationship::labelOf($relationship->relation_type),
                'is_symmetric'   => $relationship->is_symmetric,
            ],
        ], 201);
    }

    /**
     * PUT /spaces/{space}/family/{id}
     */
    public function update(Request $request, $spaceId, $relationshipId)
    {
        $space = Space::find($spaceId);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'entity.edit');

        $relationship = FamilyRelationship::where('id', $relationshipId)
            ->where('space_id', $space->id)
            ->first();

        if (!$relationship) {
            return response()->json(['error' => 'Vínculo não encontrado.'], 404);
        }

        $validated = $request->validate([
            'relation_type' => 'required|string|in:' . implode(',', FamilyRelationship::TYPES),
            'note'          => 'sometimes|nullable|string|max:255',
        ]);

        $type = $validated['relation_type'];
        $isSymmetric = FamilyRelationship::isSymmetric($type);

        if ($relationship->relation_type !== $type) {
            if ($this->relationExists($space->id, $relationship->from_entity_id, $relationship->to_entity_id, $type, $isSymmetric, $relationship->id)) {
                return response()->json([
                    'error' => 'Este vínculo já está cadastrado.',
                    'code'  => 'DUPLICATE_RELATION',
                ], 422);
            }

            if (!$isSymmetric && $this->wouldCreateVerticalCycle($space->id, $relationship->from_entity_id, $relationship->to_entity_id, $type, $relationship->id)) {
                return response()->json([
                    'error' => 'Este vínculo criaria um ciclo impossível na árvore (a mesma pessoa como ascendente e descendente).',
                    'code'  => 'CYCLE_DETECTED',
                ], 422);
            }
        }

        $relationship->update([
            'relation_type' => $type,
            'is_symmetric'  => $isSymmetric,
            'note'          => array_key_exists('note', $validated) ? $validated['note'] : $relationship->note,
        ]);

        return response()->json([
            'message'      => 'Vínculo atualizado.',
            'relationship' => [
                'id'             => $relationship->id,
                'from_entity_id' => $relationship->from_entity_id,
                'to_entity_id'   => $relationship->to_entity_id,
                'relation_type'  => $relationship->relation_type,
                'label'          => FamilyRelationship::labelOf($relationship->relation_type),
                'is_symmetric'   => $relationship->is_symmetric,
            ],
        ]);
    }

    /**
     * DELETE /spaces/{space}/family/{id}
     */
    public function destroy(Request $request, $spaceId, $relationshipId)
    {
        $space = Space::find($spaceId);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'entity.edit');

        $relationship = FamilyRelationship::where('id', $relationshipId)
            ->where('space_id', $space->id)
            ->first();

        if (!$relationship) {
            return response()->json(['error' => 'Vínculo não encontrado.'], 404);
        }

        // SoftDelete: histórico de quem ligou quem e quando é rastreabilidade,
        // e some da árvore do mesmo jeito.
        $relationship->delete();

        return response()->json(['message' => 'Vínculo removido.']);
    }

    /**
     * O vínculo já existe? Em relação simétrica, verifica os dois sentidos.
     */
    private function relationExists(int $spaceId, int $from, int $to, string $type, bool $isSymmetric, ?int $excludeId = null): bool
    {
        $query = FamilyRelationship::where('space_id', $spaceId)
            ->where('relation_type', $type);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        if ($isSymmetric) {
            return $query->where(function ($q) use ($from, $to) {
                $q->where(function ($inner) use ($from, $to) {
                    $inner->where('from_entity_id', $from)->where('to_entity_id', $to);
                })->orWhere(function ($inner) use ($from, $to) {
                    $inner->where('from_entity_id', $to)->where('to_entity_id', $from);
                });
            })->exists();
        }

        return $query->where('from_entity_id', $from)
            ->where('to_entity_id', $to)
            ->exists();
    }

    /**
     * O novo vínculo fecharia um ciclo na direção vertical?
     *
     * Percorre os ascendentes de `from` procurando `to`: se `to` já é
     * ascendente de `from`, torná-lo descendente fecharia o ciclo.
     *
     * A busca é limitada a 10 níveis. Não é preguiça: 10 gerações já é mais
     * do que qualquer árvore real cadastrada à mão, e o limite garante que
     * um dado corrompido nunca vire laço infinito numa request.
     */
    private function wouldCreateVerticalCycle(int $spaceId, int $from, int $to, string $type, ?int $excludeId = null): bool
    {
        $verticalTypes = [
            FamilyRelationship::PARENT_OF,
            FamilyRelationship::CHILD_OF,
            FamilyRelationship::GRANDPARENT_OF,
            FamilyRelationship::GRANDCHILD_OF,
        ];

        if (!in_array($type, $verticalTypes, true)) {
            return false;
        }

        // Para `parent_of`, o ascendente de X são os `from` de (from → X).
        // Para `child_of`, a direção se inverte.
        $descendantDirection = in_array($type, [FamilyRelationship::PARENT_OF, FamilyRelationship::GRANDPARENT_OF], true);

        $frontier = [$from];
        $visited = [];

        for ($depth = 0; $depth < 10 && !empty($frontier); $depth++) {
            $query = FamilyRelationship::where('space_id', $spaceId)
                ->whereIn('relation_type', $verticalTypes);

            if ($excludeId) {
                $query->where('id', '!=', $excludeId);
            }

            $next = $descendantDirection
                ? $query->whereIn('to_entity_id', $frontier)->pluck('from_entity_id')->all()
                : $query->whereIn('from_entity_id', $frontier)->pluck('to_entity_id')->all();

            if (in_array($to, $next, true)) {
                return true;
            }

            $visited = array_merge($visited, $frontier);
            $frontier = array_values(array_diff($next, $visited));
        }

        return false;
    }
}
