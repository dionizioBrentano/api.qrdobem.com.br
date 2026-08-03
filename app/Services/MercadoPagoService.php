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

        Log::error('MercadoPago createPreference falhou', [
            'external_reference' => $order->external_reference,
            'http_status' => $response->status(),
            'body' => $response->json(),
        ]);

        return null;
    }

    /**
     * Cria um pagamento PIX no Mercado Pago (Checkout API).
     * Não logamos o token.
     */
    public function createPixPayment(
        CreditOrder $order,
        string $payerEmail,
        ?string $payerName = null,
        ?string $payerCpf = null
    ): ?array {
        $token = $this->getAccessToken();

        $payer = ['email' => $payerEmail];

        // Conforme ARQUITETURA_REGISTRO_VALIDACAO.md (seção 8), o PIX exige
        // nome completo e CPF do pagador — não só o e-mail.
        if ($payerName) {
            $parts = preg_split('/\s+/', trim($payerName), 2);
            $payer['first_name'] = $parts[0];
            if (!empty($parts[1])) {
                $payer['last_name'] = $parts[1];
            }
        }

        if ($payerCpf) {
            $payer['identification'] = [
                'type' => 'CPF',
                'number' => preg_replace('/\D/', '', $payerCpf),
            ];
        }

        $payload = [
            'transaction_amount' => (float) $order->price_amount,
            'description' => 'Créditos QR do Bem',
            'payment_method_id' => 'pix',
            'payer' => $payer,
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

        Log::error('MercadoPago createPixPayment falhou', [
            'external_reference' => $order->external_reference,
            'http_status' => $response->status(),
            'body' => $response->json(),
            'mode' => config('mercadopago.mode'),
        ]);

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
        } catch (ConnectionException $e) {
            Log::error('MercadoPago createCardPayment: timeout/conexão', [
                'external_reference' => $order->external_reference,
                'message' => $e->getMessage(),
            ]);
            return null;
        }

        if ($response->successful()) {
            return $response->json();
        }

        // O corpo do erro do Mercado Pago fica só no log — nunca na resposta HTTP,
        // para não vazar detalhes internos ao cliente. Consultar em
        // storage/logs/laravel.log quando um pagamento falhar.
        Log::error('MercadoPago createCardPayment falhou', [
            'external_reference' => $order->external_reference,
            'http_status' => $response->status(),
            'body' => $response->json(),
            // Payload sem o token do cartão.
            'sent_payload' => array_diff_key($payload, ['token' => '']),
        ]);

        return null;
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
