<?php

namespace App\Services;

use App\Models\CreditOrder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;

class MercadoPagoService
{
    /**
     * Retorna o access token baseado no mode atual.
     */
    private function getAccessToken(): string
    {
        $mode = config('mercadopago.mode');
        return $mode === 'prod' 
            ? config('mercadopago.access_token_prod') 
            : config('mercadopago.access_token_test');
    }

    /**
     * Retorna a public key baseada no mode atual.
     */
    public function getPublicKey(): string
    {
        $mode = config('mercadopago.mode');
        return $mode === 'prod'
            ? config('mercadopago.public_key_prod')
            : config('mercadopago.public_key_test');
    }

    /**
     * DEBUG TEMPORÁRIO — expõe apenas o PREFIXO das credenciais em uso,
     * para confirmar se Public Key e Access Token são do mesmo ambiente.
     * Nunca expõe a credencial completa. Remover quando o checkout estabilizar.
     */
    public function getEnvironmentInfo(): array
    {
        $accessToken = $this->getAccessToken();
        $publicKey = $this->getPublicKey();

        $prefix = fn (?string $v) => $v
            ? substr($v, 0, strpos($v, '-') !== false ? strpos($v, '-') : 6)
            : 'VAZIO';

        $info = [
            'mode' => config('mercadopago.mode'),
            'access_token_prefix' => $prefix($accessToken),
            'public_key_prefix' => $prefix($publicKey),
            'prefix_match' => $prefix($accessToken) === $prefix($publicKey),
            // Trecho do meio da public key, para você conferir no painel se é
            // a mesma aplicação de onde saiu o Access Token.
            'public_key_tail' => $publicKey ? substr($publicKey, -6) : null,
        ];

        // Descobre a QUAL CONTA o Access Token pertence. Se não for a mesma
        // conta/aplicação que gerou a Public Key, o token do cartão criado no
        // navegador não existe para esta conta -> "Cannot infer Payment Method".
        try {
            $me = Http::withToken($accessToken)
                ->timeout(8)
                ->get('https://api.mercadopago.com/users/me');

            if ($me->successful()) {
                $info['access_token_account'] = [
                    'user_id' => $me->json('id'),
                    'nickname' => $me->json('nickname'),
                    'site_id' => $me->json('site_id'),
                    'email' => $me->json('email'),
                ];
            } else {
                $info['access_token_account'] = ['erro' => $me->status()];
            }
        } catch (\Throwable $e) {
            $info['access_token_account'] = ['erro' => $e->getMessage()];
        }

        return $info;
    }

    /**
     * Cria uma preference de checkout no Mercado Pago.
     * Não logamos o token.
     */
    public function createPreference(CreditOrder $order): ?array
    {
        $token = $this->getAccessToken();
        
        $frontendUrl = config('mercadopago.frontend_url');

        $payload = [
            'items' => [
                [
                    'title' => 'Créditos QR do Bem',
                    'quantity' => 1,
                    'unit_price' => (float) $order->price_amount,
                    'currency_id' => 'BRL',
                ]
            ],
            'external_reference' => $order->external_reference,
            'metadata' => [
                'tenant_id' => $order->tenant_id,
                'organization_id' => $order->organization_id,
                'quantity' => $order->quantity,
                'order_id' => $order->id,
            ],
            'back_urls' => [
                'success' => rtrim($frontendUrl, '/') . '/dashboard?credits=success',
                'pending' => rtrim($frontendUrl, '/') . '/dashboard?credits=pending',
                'failure' => rtrim($frontendUrl, '/') . '/dashboard?credits=failure',
            ],
            'auto_return' => 'approved',
        ];

        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->connectTimeout(5)
                ->post('https://api.mercadopago.com/checkout/preferences', $payload);
        } catch (ConnectionException $e) {
            Log::error('MercadoPago createPreference: timeout/conexão', [
                'external_reference' => $order->external_reference,
                'message' => $e->getMessage(),
            ]);
            return null;
        }

        if ($response->successful()) {
            return $response->json();
        }

        return null;
    }

    /**
     * Cria um pagamento PIX no Mercado Pago (Checkout API).
     * Não logamos o token.
     */
    public function createPixPayment(CreditOrder $order, string $payerEmail): ?array
    {
        $token = $this->getAccessToken();

        $payload = [
            'transaction_amount' => (float) $order->price_amount,
            'description' => 'Créditos QR do Bem',
            'payment_method_id' => 'pix',
            'payer' => [
                'email' => $payerEmail,
            ],
            'external_reference' => $order->external_reference,
            'metadata' => [
                'tenant_id' => $order->tenant_id,
                'organization_id' => $order->organization_id,
                'quantity' => $order->quantity,
                'order_id' => $order->id,
            ],
        ];

        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->connectTimeout(5)
                ->withHeaders([
                    'X-Idempotency-Key' => $order->external_reference,
                ])
                ->post('https://api.mercadopago.com/v1/payments', $payload);
        } catch (ConnectionException $e) {
            Log::error('MercadoPago createPixPayment: timeout/conexão', [
                'external_reference' => $order->external_reference,
                'message' => $e->getMessage(),
            ]);
            return null;
        }

        if ($response->successful()) {
            return $response->json();
        }

        return null;
    }

    /**
     * Cria um pagamento com Cartão via Checkout API (Payment Brick).
     * Não logamos o token.
     */
    public function createCardPayment(
        CreditOrder $order,
        string $payerEmail,
        string $token,
        string $paymentMethodId,
        int $installments = 1,
        ?string $issuerId = null,
        ?string $identificationType = null,
        ?string $identificationNumber = null
    ): ?array {
        $accessToken = $this->getAccessToken();

        $payer = [
            'email' => $payerEmail,
        ];

        if ($identificationNumber) {
            $payer['identification'] = [
                'type' => $identificationType ?? 'CPF',
                'number' => preg_replace('/\D/', '', $identificationNumber),
            ];
        }

        $payload = [
            'transaction_amount' => (float) $order->price_amount,
            'description' => 'Créditos QR do Bem',
            'payment_method_id' => $paymentMethodId,
            'token' => $token,
            'installments' => $installments,
            'payer' => $payer,
            'external_reference' => $order->external_reference,
            'metadata' => [
                'tenant_id' => $order->tenant_id,
                'organization_id' => $order->organization_id,
                'quantity' => $order->quantity,
                'order_id' => $order->id,
            ],
        ];

        if ($issuerId) {
            $payload['issuer_id'] = $issuerId;
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->connectTimeout(5)
                ->withHeaders([
                    'X-Idempotency-Key' => $order->external_reference,
                ])
                ->post('https://api.mercadopago.com/v1/payments', $payload);

            // O erro 2131 ("Cannot infer Payment Method") pode ser causado por um
            // issuer_id incompatível com a combinação cartão/bandeira. O token já
            // identifica o cartão, então tentamos de novo sem ele antes de desistir.
            $isInferenceError = $response->status() === 400
                && collect($response->json('cause') ?? [])->contains(fn ($c) => ($c['code'] ?? null) == 2131);

            $attempts = ['completo' => $response->status()];

            if ($isInferenceError && isset($payload['issuer_id'])) {
                Log::warning('MercadoPago: erro 2131 com issuer_id. Repetindo sem issuer_id.', [
                    'external_reference' => $order->external_reference,
                ]);

                unset($payload['issuer_id']);

                $response = Http::withToken($accessToken)
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->withHeaders([
                        // Idempotency key precisa ser diferente na nova tentativa.
                        'X-Idempotency-Key' => $order->external_reference . '-r2',
                    ])
                    ->post('https://api.mercadopago.com/v1/payments', $payload);

                $attempts['sem_issuer_id'] = $response->status();

                $isInferenceError = $response->status() === 400
                    && collect($response->json('cause') ?? [])->contains(fn ($c) => ($c['code'] ?? null) == 2131);
            }

            // DIAGNÓSTICO: payload mínimo, só com o que a doc marca como
            // obrigatório. Se ISTO passar, o problema está em algum campo extra
            // que enviamos. Se falhar igual, o problema é o token/cartão/conta —
            // e o formato do nosso request está descartado como causa.
            if ($isInferenceError) {
                $minimal = [
                    'transaction_amount' => (float) $order->price_amount,
                    'token' => $token,
                    'description' => 'Créditos QR do Bem',
                    'installments' => $installments,
                    'payment_method_id' => $paymentMethodId,
                    'payer' => ['email' => $payerEmail],
                ];

                Log::warning('MercadoPago: erro 2131 persistiu. Tentando payload mínimo.', [
                    'external_reference' => $order->external_reference,
                ]);

                $response = Http::withToken($accessToken)
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->withHeaders([
                        'X-Idempotency-Key' => $order->external_reference . '-r3',
                    ])
                    ->post('https://api.mercadopago.com/v1/payments', $minimal);

                $attempts['minimo'] = $response->status();
                $payload = $minimal;
            }

            $payload['_tentativas'] = $attempts;
        } catch (ConnectionException $e) {
            Log::error('MercadoPago createCardPayment: timeout/conexão', [
                'external_reference' => $order->external_reference,
                'message' => $e->getMessage(),
            ]);
            return [
                '_error' => true,
                '_status' => 0,
                '_body' => ['connection_error' => $e->getMessage()],
                '_sent_payload' => $payload,
            ];
        }

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('MercadoPago createCardPayment falhou', [
            'external_reference' => $order->external_reference,
            'http_status' => $response->status(),
            'body' => $response->json(),
            'sent_payload' => $payload,
        ]);

        // DEBUG TEMPORÁRIO: devolve também o payload EXATO enviado ao Mercado
        // Pago, para comparar com a documentação sem depender de teste manual.
        return [
            '_error' => true,
            '_status' => $response->status(),
            '_body' => $response->json(),
            '_sent_payload' => $payload,
        ];
    }

    /**
     * Busca os detalhes de um pagamento no Mercado Pago.
     */
    public function getPayment(string $id): ?array
    {
        $token = $this->getAccessToken();

        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->connectTimeout(5)
                ->get("https://api.mercadopago.com/v1/payments/{$id}");
        } catch (ConnectionException $e) {
            Log::error('MercadoPago getPayment: timeout/conexão', [
                'payment_id' => $id,
                'message' => $e->getMessage(),
            ]);
            return null;
        }

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('MercadoPago getPayment falhou', [
            'payment_id' => $id,
            'http_status' => $response->status(),
        ]);

        return null;
    }
}
