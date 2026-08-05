<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Models\Space;
use App\Policies\SpacePolicy;
use Illuminate\Http\Request;

/**
 * ApiKeyController — emissão e revogação de chaves de integração.
 * Fase 5, T3-R01 do PLANO_TRILHAS_2026-08.md.
 *
 * O SEGREDO APARECE UMA VEZ SÓ
 * Na criação. Depois disso nem nós conseguimos recuperá-lo — só o hash
 * fica no banco. Perdeu, emite outra e revoga a anterior. É o mesmo
 * contrato de qualquer provedor sério, e o que impede que um vazamento do
 * nosso banco entregue credencial pronta para uso.
 *
 * ENDPOINTS
 *   GET    /spaces/{space}/api-keys
 *   POST   /spaces/{space}/api-keys
 *   DELETE /api-keys/{key}            revoga
 */
class ApiKeyController extends Controller
{
    /** GET /spaces/{space}/api-keys */
    public function index(Request $request, $spaceId)
    {
        $space = Space::find($spaceId);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'space.edit');

        $keys = ApiKey::where('space_id', $space->id)->orderByDesc('id')->get();

        return response()->json([
            'api_keys' => $keys->map(fn (ApiKey $k) => [
                'id'           => $k->id,
                'name'         => $k->name,
                'key_id'       => $k->key_id,
                'scopes'       => $k->scopes,
                'rate_limit'   => $k->rate_limit_per_minute,
                'last_used_at' => $k->last_used_at,
                'expires_at'   => $k->expires_at,
                'revoked'      => $k->revoked_at !== null,
            ])->values(),
            'available_scopes' => ApiKey::SCOPES,
        ]);
    }

    /**
     * POST /spaces/{space}/api-keys
     * Body: name, scopes[], rate_limit?
     */
    public function store(Request $request, $spaceId)
    {
        $space = Space::find($spaceId);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'space.edit');

        $validated = $request->validate([
            'name'       => 'required|string|max:255',
            'scopes'     => 'required|array|min:1',
            'scopes.*'   => 'string|in:' . implode(',', ApiKey::SCOPES),
            'rate_limit' => 'sometimes|integer|min:1|max:600',
        ]);

        [$key, $secret] = ApiKey::issue(
            $space,
            $validated['name'],
            $validated['scopes'],
            $validated['rate_limit'] ?? 60
        );

        return response()->json([
            'message' => 'Chave criada.',
            'api_key' => [
                'id'     => $key->id,
                'name'   => $key->name,
                'key_id' => $key->key_id,
                'scopes' => $key->scopes,
            ],
            // Única vez que o segredo existe em claro.
            'secret'  => $secret,
            'warning' => 'Guarde o segredo agora. Ele não será exibido de novo.',
            'usage'   => [
                'X-Api-Key'    => $key->key_id,
                'X-Api-Secret' => '<o segredo acima>',
                'base_url'     => url('/api/v1'),
            ],
        ], 201);
    }

    /**
     * DELETE /api-keys/{key} — revoga.
     *
     * Revoga em vez de apagar: o histórico de qual chave fez o quê precisa
     * continuar apontando para um registro existente.
     */
    public function revoke(Request $request, $keyId)
    {
        $key = ApiKey::find($keyId);

        if (!$key) {
            return response()->json(['error' => 'Chave não encontrada.'], 404);
        }

        $space = Space::find($key->space_id);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'space.edit');

        $key->update(['revoked_at' => now()]);

        return response()->json(['message' => 'Chave revogada.']);
    }
}
