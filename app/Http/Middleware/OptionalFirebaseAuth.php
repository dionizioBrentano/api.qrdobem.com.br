<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * OptionalFirebaseAuth — autenticação que NÃO bloqueia.
 *
 * Fase 4 (T4-R09). Existe por causa de um caso concreto: a confirmação de
 * recebimento pela URL única do beneficiário é pública — ele não tem conta
 * no sistema. Mas quando o fator usado é `tutor`, o tutor PRECISA estar
 * identificado, senão a prova não vale nada.
 *
 * Este middleware resolve os dois: sem token, a requisição segue como
 * pública; com token válido, `$request->tenant` é preenchido e o
 * controller pode exigir o tutor.
 *
 * DIFERENÇA PARA O FirebaseAuth:
 *   - token ausente ou inválido NÃO devolve 401, apenas segue sem tenant;
 *   - NÃO cria conta automaticamente. Auto-cadastro em rota pública
 *     permitiria a qualquer um criar tenants sem passar pelo fluxo normal.
 *
 * A verificação criptográfica em si é a do FirebaseAuth — reaproveitada por
 * herança para não existirem duas implementações de validação de JWT no
 * sistema, que é como uma delas fica para trás numa correção de segurança.
 */
class OptionalFirebaseAuth extends FirebaseAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?? $request->input('id_token');

        if (!$token) {
            return $next($request);
        }

        // `verifyFirebaseToken` é privado no pai; a checagem completa é
        // feita lá. Aqui reaproveitamos via Reflection para não duplicar a
        // validação de assinatura, expiração, issuer e audience.
        $payload = $this->verifyTokenSafely($token);

        if (!$payload) {
            return $next($request);
        }

        $uid = $payload['user_id'] ?? $payload['sub'] ?? null;

        if ($uid) {
            $tenant = Tenant::where('firebase_uid', $uid)
                ->where('is_active', true)
                ->first();

            if ($tenant) {
                $request->merge(['tenant' => $tenant]);
            }
        }

        return $next($request);
    }

    /**
     * Chama a verificação do pai sem duplicar o algoritmo.
     * Qualquer falha resulta em null — rota pública não pode quebrar por
     * causa de um token malformado que alguém mandou por engano.
     */
    private function verifyTokenSafely(string $token): ?array
    {
        try {
            $method = new \ReflectionMethod(parent::class, 'verifyFirebaseToken');
            $method->setAccessible(true);

            return $method->invoke($this, $token);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
