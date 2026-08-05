<?php

namespace App\Http\Controllers;

use App\Models\ConfirmationActor;
use App\Models\ConfirmationEvent;
use App\Models\ConfirmationTemplate;
use App\Models\Entity;
use App\Models\Space;
use App\Policies\SpacePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * ConfirmationController — o motor genérico de confirmação autenticada.
 * Fase 5, T3-R05, T3-R06 e T3-R07 do PLANO_TRILHAS_2026-08.md.
 *
 * UM MOTOR, TRÊS CASOS DE USO (e os que vierem)
 *   EPI         → leitura do QR + senha do funcionário = comprovante
 *   Logística   → liberação de material para terceirizado
 *   Condomínio  → entrega de encomenda com courrier centralizado
 *
 * São o mesmo primitivo: quem, o quê, quando, onde, com qual prova. O que
 * muda entre eles são os CAMPOS e as EXIGÊNCIAS — que vivem no template,
 * como configuração.
 *
 * O EVENTO É IMUTÁVEL
 * Não existe endpoint de edição. Comprovante que pode ser alterado depois
 * não comprova nada — e este registro é o que a empresa vai apresentar numa
 * auditoria ou num processo trabalhista. Correção se faz com evento novo.
 *
 * ENDPOINTS
 *   GET    /spaces/{space}/confirmation-templates
 *   POST   /spaces/{space}/confirmation-templates
 *   POST   /spaces/{space}/confirmation-actors
 *   POST   /spaces/{space}/confirmation-actors/{actor}/password
 *   POST   /entities/{unique_code}/confirm   (a leitura do QR)
 *   GET    /spaces/{space}/confirmations     (relatório)
 */
class ConfirmationController extends Controller
{
    /** GET /spaces/{space}/confirmation-templates */
    public function templates(Request $request, $spaceId)
    {
        $space = Space::find($spaceId);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'entity.view');

        $templates = ConfirmationTemplate::where('space_id', $space->id)->get();

        return response()->json([
            'templates' => $templates->map(fn (ConfirmationTemplate $t) => [
                'id'                => $t->id,
                'name'              => $t->name,
                'slug'              => $t->slug,
                'use_case'          => $t->use_case,
                'fields'            => $t->fields,
                'requires_password' => $t->requires_password,
                'requires_photo'    => $t->requires_photo,
                'is_active'         => $t->is_active,
            ])->values(),
            // Moldes prontos dos três casos do requisito, para o parceiro
            // não começar de uma tela em branco.
            'presets' => ConfirmationTemplate::PRESETS,
        ]);
    }

    /**
     * POST /spaces/{space}/confirmation-templates
     * Body: use_case, name?, fields?, requires_password?, requires_photo?
     *
     * Informando só o `use_case`, o template nasce do preset. É o caminho
     * esperado: o parceiro escolhe "EPI" e já tem os campos certos.
     */
    public function storeTemplate(Request $request, $spaceId)
    {
        $space = Space::find($spaceId);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'space.edit');

        $validated = $request->validate([
            'use_case'          => 'required|string|in:' . implode(',', ConfirmationTemplate::USE_CASES),
            'name'              => 'sometimes|string|max:255',
            'fields'            => 'sometimes|array',
            'requires_password' => 'sometimes|boolean',
            'requires_photo'    => 'sometimes|boolean',
        ]);

        $preset = ConfirmationTemplate::PRESETS[$validated['use_case']] ?? [
            'name' => 'Confirmação', 'fields' => [], 'requires_password' => true, 'requires_photo' => false,
        ];

        $name = $validated['name'] ?? $preset['name'];
        $slug = Str::slug($name) ?: $validated['use_case'];

        // Slug repetido no mesmo espaço é erro de operação, não de sistema:
        // a mensagem precisa dizer isso, em vez de estourar violação de
        // unicidade do banco na cara do usuário.
        if (ConfirmationTemplate::where('space_id', $space->id)->where('slug', $slug)->exists()) {
            return response()->json([
                'error' => 'Já existe um modelo com este nome neste espaço.',
                'code'  => 'DUPLICATE_TEMPLATE',
            ], 422);
        }

        $template = ConfirmationTemplate::create([
            'space_id'          => $space->id,
            'name'              => $name,
            'slug'              => $slug,
            'use_case'          => $validated['use_case'],
            'fields'            => $validated['fields'] ?? $preset['fields'],
            'requires_password' => $validated['requires_password'] ?? $preset['requires_password'],
            'requires_photo'    => $validated['requires_photo'] ?? $preset['requires_photo'],
            'is_active'         => true,
        ]);

        return response()->json([
            'message'  => 'Modelo criado.',
            'template' => $template,
        ], 201);
    }

    /** POST /spaces/{space}/confirmation-actors */
    public function storeActor(Request $request, $spaceId)
    {
        $space = Space::find($spaceId);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'member.invite');

        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'external_id' => 'sometimes|nullable|string|max:60',
            'role'        => 'sometimes|nullable|string|max:60',
            'password'    => 'sometimes|nullable|string|min:4|max:64',
        ]);

        $actor = ConfirmationActor::create([
            'space_id'    => $space->id,
            'name'        => $validated['name'],
            'external_id' => $validated['external_id'] ?? null,
            'role'        => $validated['role'] ?? null,
            'is_active'   => true,
        ]);

        if (!empty($validated['password'])) {
            $actor->setPassword($validated['password']);
        }

        return response()->json([
            'message' => 'Confirmador cadastrado.',
            'actor'   => [
                'id'          => $actor->id,
                'name'        => $actor->name,
                'external_id' => $actor->external_id,
                'has_password' => $actor->password_hash !== null,
            ],
        ], 201);
    }

    /** POST /spaces/{space}/confirmation-actors/{actor}/password */
    public function setActorPassword(Request $request, $spaceId, $actorId)
    {
        $space = Space::find($spaceId);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'member.invite');

        $actor = ConfirmationActor::where('id', $actorId)
            ->where('space_id', $space->id)
            ->first();

        if (!$actor) {
            return response()->json(['error' => 'Confirmador não encontrado.'], 404);
        }

        $validated = $request->validate([
            'password' => 'required|string|min:4|max:64',
        ]);

        $actor->setPassword($validated['password']);

        return response()->json(['message' => 'Senha definida.']);
    }

    /**
     * POST /entities/{unique_code}/confirm  — a leitura do QR.
     * Body: template_slug, actor_external_id, password?, payload{}, latitude?, longitude?
     *
     * Rota pública com throttle: quem confirma é o funcionário no chão de
     * fábrica ou o porteiro na guarita, com o celular na mão, sem conta no
     * sistema. A prova vem da senha do confirmador — que é exatamente o
     * que o requisito de EPI pede.
     */
    public function confirm(Request $request, $uniqueCode)
    {
        $entity = Entity::where('unique_code', $uniqueCode)
            ->where('is_active', true)
            ->first();

        if (!$entity || !$entity->space_id) {
            return response()->json(['error' => 'QR Code não encontrado.'], 404);
        }

        $space = Space::find($entity->space_id);

        if (!$space) {
            return response()->json(['error' => 'QR Code não encontrado.'], 404);
        }

        $validated = $request->validate([
            'template_slug'     => 'required|string|max:60',
            'actor_external_id' => 'required|string|max:60',
            'password'          => 'sometimes|nullable|string|max:64',
            'payload'           => 'sometimes|array',
            'latitude'          => 'sometimes|nullable|numeric|between:-90,90',
            'longitude'         => 'sometimes|nullable|numeric|between:-180,180',
        ]);

        $template = ConfirmationTemplate::where('space_id', $space->id)
            ->where('slug', $validated['template_slug'])
            ->where('is_active', true)
            ->first();

        if (!$template) {
            return response()->json([
                'error' => 'Modelo de confirmação não encontrado.',
                'code'  => 'TEMPLATE_NOT_FOUND',
            ], 404);
        }

        $actor = ConfirmationActor::where('space_id', $space->id)
            ->where('external_id', $validated['actor_external_id'])
            ->where('is_active', true)
            ->first();

        if (!$actor) {
            return response()->json([
                'error' => 'Confirmador não encontrado.',
                'code'  => 'ACTOR_NOT_FOUND',
            ], 404);
        }

        // A senha do funcionário — o segundo fator do requisito de EPI.
        $passwordVerified = false;

        if ($template->requires_password) {
            if (empty($validated['password']) || !$actor->checkPassword($validated['password'])) {
                return response()->json([
                    'error' => 'Senha incorreta.',
                    'code'  => 'INVALID_PASSWORD',
                ], 422);
            }

            $passwordVerified = true;
        }

        $payload = $validated['payload'] ?? [];
        $errors = $template->validatePayload($payload);

        if (!empty($errors)) {
            return response()->json([
                'error'  => 'Campos obrigatórios não preenchidos.',
                'code'   => 'INVALID_PAYLOAD',
                'fields' => $errors,
            ], 422);
        }

        $event = ConfirmationEvent::create([
            'space_id'          => $space->id,
            'template_id'       => $template->id,
            'entity_id'         => $entity->id,
            'actor_id'          => $actor->id,
            'payload'           => $payload,
            'password_verified' => $passwordVerified,
            'ip_address'        => $request->ip(),
            'user_agent'        => substr((string) $request->userAgent(), 0, 500),
            'latitude'          => $validated['latitude'] ?? null,
            'longitude'         => $validated['longitude'] ?? null,
            'confirmed_at'      => now(),
        ]);

        return response()->json([
            'message' => 'Confirmação registrada.',
            'event'   => [
                'id'           => $event->id,
                'template'     => $template->name,
                'actor'        => $actor->name,
                'confirmed_at' => $event->confirmed_at,
            ],
        ], 201);
    }

    /**
     * GET /spaces/{space}/confirmations
     * Relatório. Filtros: ?template_slug= &from= &to= &format=csv
     *
     * O CSV atende o T3-R08: faturamento e nota fiscal estão FORA de
     * escopo; o que o sistema faz é exportar os dados de consumo para a
     * contabilidade externa.
     */
    public function index(Request $request, $spaceId)
    {
        $space = Space::find($spaceId);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'entity.view');

        $query = ConfirmationEvent::with(['template', 'actor'])
            ->where('space_id', $space->id);

        if ($slug = $request->input('template_slug')) {
            $template = ConfirmationTemplate::where('space_id', $space->id)
                ->where('slug', $slug)
                ->first();

            $query->where('template_id', $template?->id ?? 0);
        }

        if ($from = $request->input('from')) {
            $query->where('confirmed_at', '>=', $from);
        }

        if ($to = $request->input('to')) {
            $query->where('confirmed_at', '<=', $to);
        }

        $events = $query->orderByDesc('confirmed_at')->limit(1000)->get();

        if ($request->input('format') === 'csv') {
            return $this->csv($events);
        }

        return response()->json([
            'events' => $events->map(fn (ConfirmationEvent $e) => [
                'id'                => $e->id,
                'template'          => $e->template?->name,
                'use_case'          => $e->template?->use_case,
                'actor'             => $e->actor?->name,
                'actor_external_id' => $e->actor?->external_id,
                'payload'           => $e->payload,
                'password_verified' => $e->password_verified,
                'confirmed_at'      => $e->confirmed_at,
            ])->values(),
            'total' => $events->count(),
        ]);
    }

    /**
     * Exportação em CSV para a contabilidade externa (T3-R08).
     *
     * Separador ponto e vírgula e BOM UTF-8: é o que o Excel em português
     * abre corretamente. Vírgula e sem BOM produz uma coluna só com
     * acentuação quebrada — e aí a exportação não serve para nada.
     */
    private function csv($events)
    {
        $lines = ["\xEF\xBB\xBF" . 'ID;Modelo;Caso de uso;Confirmador;Matricula;Senha conferida;Data;Dados'];

        foreach ($events as $event) {
            $payload = collect($event->payload ?? [])
                ->map(fn ($v, $k) => "{$k}={$v}")
                ->implode(' | ');

            $lines[] = implode(';', [
                $event->id,
                $this->csvCell($event->template?->name),
                $this->csvCell($event->template?->use_case),
                $this->csvCell($event->actor?->name),
                $this->csvCell($event->actor?->external_id),
                $event->password_verified ? 'Sim' : 'Nao',
                $event->confirmed_at?->format('d/m/Y H:i'),
                $this->csvCell($payload),
            ]);
        }

        return response(implode("\r\n", $lines), 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="confirmacoes.csv"',
        ]);
    }

    /** Neutraliza o separador dentro do conteúdo da célula. */
    private function csvCell(?string $value): string
    {
        return str_replace([';', "\r", "\n"], [',', ' ', ' '], (string) $value);
    }
}
