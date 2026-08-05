<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * ApiKeyAuth — autenticação da API pública por chave de parceiro.
 * Fase 5, T3-R01 do PLANO_TRILHAS_2026-08.md.
 *
 * Headers esperados:
 *   X-Api-Key:    qdb_xxxxxxxxxxxx
 *   X-Api-Secret: <segredo>
 *
 * Dois headers em vez de um Bearer concatenado: o `key_id` localiza o
 * registro sem varrer a tabela testando bcrypt contra cada linha — o que
 * seria O(n) verificações por requisição, e derrubaria a API assim que
 * houvesse alguns parceiros.
 *
 * RATE LIMIT POR PARCEIRO
 * Cada chave tem o seu teto. É por parceiro, e não global, porque um
 * integrador mal configurado não pode derrubar a API para os outros.
 *
 * ESCOPO
 * O escopo exigido vem como parâmetro do middleware:
 *   ->middleware('api.key:entities.read')
 * Assim a rota declara o que precisa, e a verificação não fica espalhada
 * dentro dos controllers.
 */
class ApiKeyAuth
{
    public function handle(Request $request, Closure $next, ?string $requiredScope = null): Response
    {
        $keyId  = $request->header('X-Api-Key');
        $secret = $request->header('X-Api-Secret');

        if (!$keyId || !$secret) {
            return response()->json([
                'error' => 'Credenciais ausentes. Envie X-Api-Key e X-Api-Secret.',
                'code'  => 'MISSING_CREDENTIALS',
            ], 401);
        }

        $apiKey = ApiKey::where('key_id', $keyId)->first();

        // Mensagem idêntica para chave inexistente e segredo errado:
        // diferenciar permitiria descobrir quais key_id existem.
        if (!$apiKey || !$apiKey->verifySecret($secret)) {
            return response()->json([
                'error' => 'Credenciais inválidas.',
                'code'  => 'INVALID_CREDENTIALS',
            ], 401);
        }

        if (!$apiKey->isUsable()) {
            return response()->json([
                'error' => 'Chave revogada ou expirada.',
                'code'  => 'KEY_NOT_USABLE',
            ], 403);
        }

        if ($requiredScope && !$apiKey->hasScope($requiredScope)) {
            return response()->json([
                'error'          => 'Esta chave não tem permissão para esta operação.',
                'code'           => 'INSUFFICIENT_SCOPE',
                'required_scope' => $requiredScope,
            ], 403);
        }

        // Rate limit por chave, em janela de 1 minuto.
        $cacheKey = 'api-key-rate:' . $apiKey->id . ':' . now()->format('YmdHi');
        $hits = (int) Cache::get($cacheKey, 0);

        if ($hits >= $apiKey->rate_limit_per_minute) {
            return response()->json([
                'error' => 'Limite de requisições por minuto excedido.',
                'code'  => 'RATE_LIMITED',
                'limit' => $apiKey->rate_limit_per_minute,
            ], 429);
        }

        // TTL de 61s: a janela é de minuto cheio, e o segundo extra evita
        // que a chave expire um instante antes da virada.
        Cache::put($cacheKey, $hits + 1, 61);

        // `last_used_at` é gravado no máximo uma vez por minuto: escrever a
        // cada requisição faria um UPDATE por chamada de API, sem ganho.
        $shouldTouch = !$apiKey->last_used_at || $apiKey->last_used_at->diffInMinutes(now()) >= 1;

        if ($shouldTouch) {
            $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->merge([
            'api_key'   => $apiKey,
            'api_space' => $apiKey->space,
        ]);

        return $next($request);
    }
}
