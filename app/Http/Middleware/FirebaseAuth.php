<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\Tenant;
use App\Models\Organization;

class FirebaseAuth
{
    /**
     * URL das chaves públicas do Google para verificação de tokens Firebase.
     */
    private const GOOGLE_CERTS_URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    /**
     * Tempo de cache das chaves públicas (em segundos). 1 hora.
     */
    private const CACHE_TTL = 3600;

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['error' => 'Token não fornecido'], 401);
        }

        // Verifica e decodifica o JWT com validação completa de assinatura
        $payload = $this->verifyFirebaseToken($token);

        if (!$payload) {
            return response()->json(['error' => 'Token inválido ou expirado'], 401);
        }

        $uid = $payload['user_id'] ?? $payload['sub'] ?? null;

        if (!$uid) {
            return response()->json(['error' => 'Token sem identificação de usuário'], 401);
        }

        $tenant = Tenant::where('firebase_uid', $uid)->where('is_active', true)->first();

        $tokenEmail = $payload['email'] ?? null;

        // Auto-cadastro para B2C (Pessoa Física) e Matriz
        if (!$tenant) {
            $email = $tokenEmail ?? 'usuario@qrdobem.com.br';
            $name = $payload['name'] ?? explode('@', $email)[0];

            DB::transaction(function () use (&$tenant, $name, $uid, $email) {
                $tenant = Tenant::create([
                    'name' => $name,
                    'email' => $email,
                    'document_number' => 'B2C-' . uniqid(),
                    'firebase_uid' => $uid,
                    'role' => 'ngo',
                    'qr_quota' => 0,
                    'profile_status' => 'incomplete',
                    'is_active' => true,
                ]);

                $org = Organization::create([
                    'name' => 'Matriz de ' . $name,
                    'owner_tenant_id' => $tenant->id,
                ]);

                $org->users()->attach($tenant->id, ['role' => 'admin']);
            });
        }

        // Backfill: tenants criados antes desta correção ficaram sem email.
        // O email é necessário para OTP, cobrança e Mercado Pago.
        if ($tokenEmail && empty($tenant->email)) {
            $tenant->update(['email' => $tokenEmail]);
        }

        // profile_status será alterado para 'active' apenas pelo fluxo de onboarding
        // (quando o usuário completar CPF, telefone, etc.)

        $request->merge(['tenant' => $tenant]);

        return $next($request);
    }

    /**
     * Verifica a assinatura RS256 do JWT Firebase usando as chaves públicas do Google.
     * Valida: assinatura, expiração, issuer e audience.
     *
     * @return array|null Payload decodificado ou null se inválido.
     */
    private function verifyFirebaseToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        // Decodifica header para pegar o kid (Key ID)
        $header = json_decode($this->base64UrlDecode($headerB64), true);
        if (!$header || ($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
            return null;
        }

        // Decodifica payload
        $payload = json_decode($this->base64UrlDecode($payloadB64), true);
        if (!$payload) {
            return null;
        }

        // Valida expiração
        if (($payload['exp'] ?? 0) < time()) {
            return null;
        }

        // Valida issued at (não aceita tokens do futuro)
        if (($payload['iat'] ?? PHP_INT_MAX) > time() + 30) {
            return null;
        }

        // Valida issuer — deve ser do projeto Firebase correto
        $projectId = config('services.firebase.project_id');
        $expectedIssuer = 'https://securetoken.google.com/' . $projectId;
        if (($payload['iss'] ?? '') !== $expectedIssuer) {
            Log::warning('FirebaseAuth: issuer inválido', [
                'expected' => $expectedIssuer,
                'received' => $payload['iss'] ?? 'ausente',
            ]);
            return null;
        }

        // Valida audience — deve ser o project ID
        if (($payload['aud'] ?? '') !== $projectId) {
            return null;
        }

        // Valida sub (não pode estar vazio)
        if (empty($payload['sub'])) {
            return null;
        }

        // Busca as chaves públicas do Google (com cache)
        $publicKeys = $this->getGooglePublicKeys();
        if (!$publicKeys) {
            Log::error('FirebaseAuth: falha ao buscar chaves públicas do Google');
            return null;
        }

        $kid = $header['kid'];
        if (!isset($publicKeys[$kid])) {
            Log::warning('FirebaseAuth: kid não encontrado nas chaves públicas', ['kid' => $kid]);
            return null;
        }

        // Verifica a assinatura RS256
        $signature = $this->base64UrlDecode($signatureB64);
        $dataToVerify = $headerB64 . '.' . $payloadB64;
        $publicKey = openssl_pkey_get_public($publicKeys[$kid]);

        if (!$publicKey) {
            Log::error('FirebaseAuth: chave pública inválida para kid ' . $kid);
            return null;
        }

        $verified = openssl_verify($dataToVerify, $signature, $publicKey, OPENSSL_ALGO_SHA256);

        if ($verified !== 1) {
            Log::warning('FirebaseAuth: assinatura JWT inválida');
            return null;
        }

        return $payload;
    }

    /**
     * Busca e cacheia as chaves públicas X.509 do Google.
     */
    private function getGooglePublicKeys(): ?array
    {
        return Cache::remember('firebase_google_public_keys', self::CACHE_TTL, function () {
            try {
                // Usa cURL (compatível com CPanel/shared hosting onde file_get_contents pode estar bloqueado)
                $ch = curl_init(self::GOOGLE_CERTS_URL);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);

                if ($response === false || $httpCode !== 200) {
                    Log::error('FirebaseAuth: falha cURL ao buscar chaves do Google', [
                        'http_code' => $httpCode,
                        'error' => $error,
                    ]);
                    return null;
                }

                $keys = json_decode($response, true);
                if (!is_array($keys) || empty($keys)) {
                    Log::error('FirebaseAuth: resposta inválida das chaves do Google');
                    return null;
                }

                return $keys;
            } catch (\Exception $e) {
                Log::error('FirebaseAuth: erro ao buscar chaves do Google', [
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        });
    }

    /**
     * Decodifica Base64 URL-safe (padrão JWT).
     */
    private function base64UrlDecode(string $input): string
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $input .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($input, '-_', '+/'));
    }
}
