<?php

namespace App\Http\Controllers;

use App\Models\CauseProfile;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Policies\SpacePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SpaceController — criação e gestão dos espaços de trilha.
 * Fase 0 (F1) e Fase 3 (T2-R01, T2-R02) do PLANO_TRILHAS_2026-08.md.
 *
 * SEM CNPJ (T2-R01)
 * Criar espaço do tipo `cause` não exige documento de pessoa jurídica.
 * Pessoa física liderando iniciativa autônoma — o caso dos Anjos do
 * Asfalto — usa o próprio CPF, que o Gate 1 já validou. Exigir CNPJ aqui
 * excluiria exatamente quem a trilha existe para atender.
 *
 * GUARDA-CHUVA (T2-R02)
 * `parent_space_id` liga um grupo menor ao espaço de uma OSCIP. Quem cria
 * o vínculo é o dono do espaço-mãe, nunca o filho: se o filho pudesse se
 * pendurar sozinho numa OSCIP, qualquer um se declararia apoiado por ela.
 *
 * ENDPOINTS
 *   GET    /spaces               espaços do usuário
 *   POST   /spaces               cria espaço
 *   GET    /spaces/{space}       detalhe
 *   PUT    /spaces/{space}       edita
 *   POST   /spaces/{space}/children   vincula grupo ao guarda-chuva
 */
class SpaceController extends Controller
{
    /** GET /spaces */
    public function index(Request $request)
    {
        $tenant = $request->tenant;

        $memberSpaceIds = SpaceMember::where('tenant_id', $tenant->id)
            ->whereNotNull('accepted_at')
            ->pluck('space_id');

        $spaces = Space::with('causeProfile')
            ->where('owner_tenant_id', $tenant->id)
            ->orWhereIn('id', $memberSpaceIds)
            ->orderBy('id')
            ->get();

        return response()->json([
            'spaces' => $spaces->map(fn (Space $s) => $this->present($s, $tenant)),
        ]);
    }

    /**
     * POST /spaces
     * Body: type, name, organization_id?, headline?, category?, city?, state?
     */
    public function store(Request $request)
    {
        $tenant = $request->tenant;

        // Gate 1: criar espaço é ato de responsabilidade — exige perfil
        // ativo, do mesmo modo que criar entidade.
        if ($tenant->profile_status !== 'active') {
            return response()->json([
                'error' => 'Complete seu perfil antes de criar um espaço.',
                'code'  => 'PROFILE_INCOMPLETE',
            ], 403);
        }

        $validated = $request->validate([
            'type'            => 'required|string|in:' . implode(',', Space::TYPES),
            'name'            => 'required|string|max:255',
            'organization_id' => 'sometimes|nullable|integer',
            // Campos da vitrine, quando o tipo é `cause`.
            'headline'        => 'sometimes|nullable|string|max:255',
            'category'        => 'sometimes|nullable|string|max:50',
            'city'            => 'sometimes|nullable|string|max:120',
            'state'           => 'sometimes|nullable|string|size:2',
            'story'           => 'sometimes|nullable|string|max:5000',
            'goal_amount'     => 'sometimes|nullable|numeric|min:0',
        ]);

        $space = DB::transaction(function () use ($validated, $tenant) {
            $space = Space::create([
                'owner_tenant_id' => $tenant->id,
                // Nulo é o caso normal da causa de pessoa física (T2-R01).
                'organization_id' => $validated['organization_id'] ?? null,
                'type'            => $validated['type'],
                'name'            => $validated['name'],
                'slug'            => Space::generateSlug($validated['name']),
                'status'          => 'active',
            ]);

            // O criador entra como owner com convite já aceito: ninguém
            // aceita convite para o próprio espaço.
            SpaceMember::create([
                'space_id'    => $space->id,
                'tenant_id'   => $tenant->id,
                'role'        => SpaceMember::ROLE_OWNER,
                'accepted_at' => now(),
            ]);

            // Vitrine nasce junto, mas despublicada: causa sem história
            // contada não deveria aparecer na lista pública.
            if ($validated['type'] === Space::TYPE_CAUSE) {
                CauseProfile::create([
                    'space_id'     => $space->id,
                    'headline'     => $validated['headline'] ?? $validated['name'],
                    'story'        => $validated['story'] ?? null,
                    'category'     => $validated['category'] ?? null,
                    'city'         => $validated['city'] ?? null,
                    'state'        => $validated['state'] ?? null,
                    'goal_amount'  => $validated['goal_amount'] ?? null,
                    'is_published' => false,
                ]);
            }

            return $space;
        });

        return response()->json([
            'message' => 'Espaço criado.',
            'space'   => $this->present($space->fresh('causeProfile'), $tenant),
        ], 201);
    }

    /** GET /spaces/{space} */
    public function show(Request $request, $spaceId)
    {
        $space = Space::with(['causeProfile', 'children'])->find($spaceId);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'entity.view');

        return response()->json([
            'space' => array_merge($this->present($space, $request->tenant), [
                'children' => $space->children->map(fn (Space $c) => [
                    'id'   => $c->id,
                    'name' => $c->name,
                    'type' => $c->type,
                    'slug' => $c->slug,
                ])->values(),
            ]),
        ]);
    }

    /** PUT /spaces/{space} */
    public function update(Request $request, $spaceId)
    {
        $space = Space::find($spaceId);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'space.edit');

        $validated = $request->validate([
            'name'     => 'sometimes|string|max:255',
            'settings' => 'sometimes|array',
            'status'   => 'sometimes|string|in:active,suspended',
        ]);

        $space->update($validated);

        return response()->json([
            'message' => 'Espaço atualizado.',
            'space'   => $this->present($space->fresh('causeProfile'), $request->tenant),
        ]);
    }

    /**
     * POST /spaces/{space}/children  { child_space_id }
     * Vincula um grupo menor a este espaço guarda-chuva (T2-R02).
     *
     * Quem chama é o DONO DO GUARDA-CHUVA. A direção importa: se o grupo
     * pudesse se pendurar sozinho numa OSCIP, qualquer um se declararia
     * apoiado por ela — e é justamente o vínculo que dá lastro fiscal ao
     * recibo do doador.
     */
    public function attachChild(Request $request, $spaceId)
    {
        $parent = Space::find($spaceId);

        if (!$parent) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $parent, 'space.edit');

        $validated = $request->validate([
            'child_space_id' => 'required|integer',
        ]);

        $child = Space::find($validated['child_space_id']);

        if (!$child) {
            return response()->json(['error' => 'Grupo não encontrado.'], 404);
        }

        if ($child->id === $parent->id) {
            return response()->json([
                'error' => 'Um espaço não pode ser guarda-chuva de si mesmo.',
                'code'  => 'SELF_PARENT',
            ], 422);
        }

        // Ciclo: A apoia B, B apoia A. Verificação subindo a cadeia, com
        // teto de profundidade para dado corrompido não virar laço infinito.
        $ancestor = $parent;
        for ($depth = 0; $depth < 10 && $ancestor?->parent_space_id; $depth++) {
            if ($ancestor->parent_space_id === $child->id) {
                return response()->json([
                    'error' => 'Este vínculo criaria um ciclo entre os espaços.',
                    'code'  => 'CYCLE_DETECTED',
                ], 422);
            }
            $ancestor = Space::find($ancestor->parent_space_id);
        }

        $child->update(['parent_space_id' => $parent->id]);

        return response()->json([
            'message' => 'Grupo vinculado ao guarda-chuva.',
            'child'   => ['id' => $child->id, 'name' => $child->name],
            // Registrado em texto porque a consequência é jurídica, não técnica.
            'note'    => 'O recibo dedutível é emitido pela entidade certificada, não pelo grupo apoiado.',
        ]);
    }

    /**
     * Forma de apresentação única, para os endpoints não divergirem entre si.
     */
    private function present(Space $space, $tenant): array
    {
        return [
            'id'              => $space->id,
            'name'            => $space->name,
            'type'            => $space->type,
            'slug'            => $space->slug,
            'status'          => $space->status,
            'organization_id' => $space->organization_id,
            'parent_space_id' => $space->parent_space_id,
            'is_owner'        => $space->owner_tenant_id === $tenant->id,
            'permissions'     => app(SpacePolicy::class)->permissionsFor($tenant, $space),
            'cause'           => $space->causeProfile ? [
                'headline'      => $space->causeProfile->headline,
                'story'         => $space->causeProfile->story,
                'category'      => $space->causeProfile->category,
                'city'          => $space->causeProfile->city,
                'state'         => $space->causeProfile->state,
                'goal_amount'   => $space->causeProfile->goal_amount,
                'raised_amount' => $space->causeProfile->raised_amount,
                'progress'      => $space->causeProfile->progressPercent(),
                'accountability'=> $space->causeProfile->accountability,
                'is_published'  => $space->causeProfile->is_published,
            ] : null,
        ];
    }
}
