<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConfirmationActor;
use App\Models\ConfirmationEvent;
use App\Models\ConfirmationTemplate;
use App\Models\Entity;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * PartnerController — a API pública versionada (/api/v1).
 * Fase 5, T3-R01 do PLANO_TRILHAS_2026-08.md.
 *
 * Autenticação por chave de parceiro (ApiKeyAuth), escopo declarado na
 * rota, rate limit por chave. O parceiro só enxerga o próprio espaço:
 * `$request->api_space` vem do middleware e é a fronteira — nenhum
 * endpoint aqui aceita `space_id` do cliente, justamente para que uma
 * chave não consiga ler dados de outro.
 *
 * VERSIONAMENTO
 * `/api/v1` fixo no caminho. Quando houver v2, as duas convivem: parceiro
 * corporativo não atualiza integração no nosso ritmo, e quebrar a API
 * deles sem aviso é a forma mais rápida de perder o contrato.
 *
 * ENDPOINTS
 *   GET  /api/v1/entities                lista os QR do parceiro
 *   POST /api/v1/entities                cria QR
 *   GET  /api/v1/entities/{code}         detalhe
 *   POST /api/v1/confirmations           registra confirmação
 *   GET  /api/v1/confirmations           consulta confirmações
 */
class PartnerController extends Controller
{
    /** GET /api/v1/entities */
    public function listEntities(Request $request)
    {
        $space = $request->api_space;

        $entities = Entity::where('space_id', $space->id)
            ->orderByDesc('id')
            ->limit((int) $request->input('limit', 100))
            ->get();

        return response()->json([
            'data' => $entities->map(fn (Entity $e) => [
                'code'       => $e->unique_code,
                'type'       => $e->type,
                'name'       => $e->encrypted_name,
                'status'     => $e->status,
                'url'        => config('qrdobem.public_base_url') . '/' . config('qrdobem.public_path_prefix') . '/' . $e->unique_code,
                'created_at' => $e->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['count' => $entities->count()],
        ]);
    }

    /** POST /api/v1/entities */
    public function createEntity(Request $request)
    {
        $space = $request->api_space;

        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'type'          => 'sometimes|string|in:person,pet,object',
            'contact_phone' => 'sometimes|nullable|string|max:30',
            'contact_email' => 'sometimes|nullable|email|max:255',
        ]);

        $entity = Entity::create([
            'organization_id' => $space->organization_id,
            'space_id'        => $space->id,
            'unique_code'     => (string) Str::uuid(),
            'type'            => $validated['type'] ?? 'object',
            'encrypted_name'  => $validated['name'],
            'encrypted_contact_phone' => $validated['contact_phone'] ?? '',
            'encrypted_contact_email' => $validated['contact_email'] ?? null,
            'is_active'       => true,
            // Nasce ativo: no B2B quem responde pelo uso é a empresa
            // parceira, que já aceitou o contrato de integração. O termo
            // por entidade é do fluxo B2C.
            'status'          => 'active',
        ]);

        return response()->json([
            'data' => [
                'code' => $entity->unique_code,
                'url'  => config('qrdobem.public_base_url') . '/' . config('qrdobem.public_path_prefix') . '/' . $entity->unique_code,
            ],
        ], 201);
    }

    /** GET /api/v1/entities/{code} */
    public function showEntity(Request $request, $code)
    {
        $space = $request->api_space;

        $entity = Entity::where('unique_code', $code)
            ->where('space_id', $space->id)
            ->first();

        if (!$entity) {
            return response()->json(['error' => 'Não encontrado.', 'code' => 'NOT_FOUND'], 404);
        }

        return response()->json([
            'data' => [
                'code'       => $entity->unique_code,
                'type'       => $entity->type,
                'name'       => $entity->encrypted_name,
                'status'     => $entity->status,
                'created_at' => $entity->created_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/v1/confirmations
     * Body: entity_code, template_slug, actor_external_id, password?, payload{}
     *
     * Mesma regra do motor interno: se o template exige senha, sem senha
     * válida não há registro. A API não é caminho de contorno da prova.
     */
    public function storeConfirmation(Request $request)
    {
        $space = $request->api_space;

        $validated = $request->validate([
            'entity_code'       => 'required|string',
            'template_slug'     => 'required|string|max:60',
            'actor_external_id' => 'required|string|max:60',
            'password'          => 'sometimes|nullable|string|max:64',
            'payload'           => 'sometimes|array',
        ]);

        $entity = Entity::where('unique_code', $validated['entity_code'])
            ->where('space_id', $space->id)
            ->first();

        if (!$entity) {
            return response()->json(['error' => 'QR Code não encontrado.', 'code' => 'NOT_FOUND'], 404);
        }

        $template = ConfirmationTemplate::where('space_id', $space->id)
            ->where('slug', $validated['template_slug'])
            ->where('is_active', true)
            ->first();

        if (!$template) {
            return response()->json(['error' => 'Modelo não encontrado.', 'code' => 'TEMPLATE_NOT_FOUND'], 404);
        }

        $actor = ConfirmationActor::where('space_id', $space->id)
            ->where('external_id', $validated['actor_external_id'])
            ->where('is_active', true)
            ->first();

        if (!$actor) {
            return response()->json(['error' => 'Confirmador não encontrado.', 'code' => 'ACTOR_NOT_FOUND'], 404);
        }

        $passwordVerified = false;

        if ($template->requires_password) {
            if (empty($validated['password']) || !$actor->checkPassword($validated['password'])) {
                return response()->json(['error' => 'Senha incorreta.', 'code' => 'INVALID_PASSWORD'], 422);
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
            'confirmed_at'      => now(),
        ]);

        return response()->json([
            'data' => [
                'id'           => $event->id,
                'confirmed_at' => $event->confirmed_at->toIso8601String(),
            ],
        ], 201);
    }

    /** GET /api/v1/confirmations */
    public function listConfirmations(Request $request)
    {
        $space = $request->api_space;

        $query = ConfirmationEvent::with(['template', 'actor'])
            ->where('space_id', $space->id);

        if ($from = $request->input('from')) {
            $query->where('confirmed_at', '>=', $from);
        }

        if ($to = $request->input('to')) {
            $query->where('confirmed_at', '<=', $to);
        }

        $events = $query->orderByDesc('confirmed_at')
            ->limit((int) $request->input('limit', 200))
            ->get();

        return response()->json([
            'data' => $events->map(fn (ConfirmationEvent $e) => [
                'id'                => $e->id,
                'template_slug'     => $e->template?->slug,
                'actor_external_id' => $e->actor?->external_id,
                'payload'           => $e->payload,
                'password_verified' => $e->password_verified,
                'confirmed_at'      => $e->confirmed_at?->toIso8601String(),
            ])->values(),
            'meta' => ['count' => $events->count()],
        ]);
    }
}
