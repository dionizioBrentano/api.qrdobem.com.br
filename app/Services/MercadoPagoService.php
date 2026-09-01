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
        private function buildUrl(string $path): string
    {
        return config('mercadopago.base_url') . '/' . ltrim($path, '/');
    }

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
                ->post($this->buildUrl('checkout/preferences'), $payload);
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
                ->post($this->buildUrl('v1/payments'), $payload);
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
                ->post($this->buildUrl('v1/payments'), $payload);
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
     * Cria um pagamento PIX de DOAÇÃO no Mercado Pago (Checkout API).
     * Não logamos o token.
     */
    public function createDonationPixPayment(
        \App\Models\DonationCause $donation,
        string $payerEmail,
        ?string $payerName = null,
        ?string $payerCpf = null
    ): ?array {
        $token = $this->getAccessToken();

        $payer = ['email' => $payerEmail];

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

        $causeName = $donation->cause?->name ?? 'QR do Bem';
        $reference = 'donation-' . $donation->id;

        $payload = [
            'transaction_amount' => (float) $donation->amount,
            'description' => 'Doação — ' . $causeName,
            'payment_method_id' => 'pix',
            'payer' => $payer,
            'external_reference' => $reference,
            'metadata' => [
                'kind' => 'donation',
                'donation_id' => $donation->id,
                'cause_space_id' => $donation->cause_space_id,
                'donor_tenant_id' => $donation->donor_tenant_id,
            ],
        ];

        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->connectTimeout(5)
                ->withHeaders([
                    'X-Idempotency-Key' => $reference,
                ])
                ->post($this->buildUrl('v1/payments'), $payload);
        } catch (ConnectionException $e) {
            Log::error('MercadoPago createDonationPixPayment: timeout/conexão', [
                'external_reference' => $reference,
                'message' => $e->getMessage(),
            ]);
            return null;
        }

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('MercadoPago createDonationPixPayment falhou', [
            'external_reference' => $reference,
            'http_status' => $response->status(),
            'body' => $response->json(),
            'mode' => config('mercadopago.mode'),
        ]);

        return null;
    }

    /**
     * Cria um pagamento com Cartão de DOAÇÃO via Checkout API (Payment Brick).
     * Não logamos o token.
     */
    public function createDonationCardPayment(
        \App\Models\DonationCause $donation,
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

        $causeName = $donation->cause?->name ?? 'QR do Bem';
        $reference = 'donation-' . $donation->id;

        $payload = [
            'transaction_amount' => (float) $donation->amount,
            'description' => 'Doação — ' . $causeName,
            'payment_method_id' => $paymentMethodId,
            'token' => $token,
            'installments' => $installments,
            'payer' => $payer,
            'external_reference' => $reference,
            'metadata' => [
                'kind' => 'donation',
                'donation_id' => $donation->id,
                'cause_space_id' => $donation->cause_space_id,
                'donor_tenant_id' => $donation->donor_tenant_id,
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
                    'X-Idempotency-Key' => $reference,
                ])
                ->post($this->buildUrl('v1/payments'), $payload);
        } catch (ConnectionException $e) {
            Log::error('MercadoPago createDonationCardPayment: timeout/conexão', [
                'external_reference' => $reference,
                'message' => $e->getMessage(),
            ]);
            return null;
        }

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('MercadoPago createDonationCardPayment falhou', [
            'external_reference' => $reference,
            'http_status' => $response->status(),
            'body' => $response->json(),
            'sent_payload' => array_diff_key($payload, ['token' => '']),
        ]);

        return null;
    }

    /**
     * Assinatura de doação recorrente — Preapproval (Fase 4, T4-R04).
     *
     * `back_url` é obrigatório pelo Mercado Pago neste recurso, mesmo que o
     * fluxo termine no app: sem ele a criação é recusada.
     */
    public function createPreapproval(\App\Models\DonationSubscription $subscription): ?array
    {
        $token = $this->getAccessToken();
        $frontendUrl = rtrim(config('mercadopago.frontend_url'), '/');

        $payerEmail = $subscription->donor?->email;

        if (!$payerEmail) {
            Log::error('MercadoPago createPreapproval: doador sem e-mail', [
                'subscription_id' => $subscription->id,
            ]);
            return null;
        }

        $causeName = $subscription->cause?->name ?? 'QR do Bem';

        $payload = [
            'reason' => 'Doação mensal — ' . $causeName,
            'external_reference' => 'subscription-' . $subscription->id,
            'payer_email' => $payerEmail,
            'back_url' => $frontendUrl . '/doacoes?subscription=ok',
            'auto_recurring' => [
                'frequency' => 1,
                'frequency_type' => 'months',
                'transaction_amount' => (float) $subscription->amount,
                'currency_id' => 'BRL',
            ],
            'status' => 'pending',
        ];

        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->connectTimeout(5)
                ->post($this->buildUrl('preapproval'), $payload);
        } catch (ConnectionException $e) {
            Log::error('MercadoPago createPreapproval: timeout/conexão', [
                'subscription_id' => $subscription->id,
                'message' => $e->getMessage(),
            ]);
            return null;
        }

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('MercadoPago createPreapproval falhou', [
            'subscription_id' => $subscription->id,
            'http_status' => $response->status(),
            'body' => $response->json(),
        ]);

        return null;
    }

    /**
     * Cancela a assinatura recorrente no Mercado Pago.
     *
     * Devolve bool em vez de array: o chamador não tem o que fazer com o
     * corpo da resposta, e um cancelamento que falha no MP não pode impedir
     * o cancelamento local — ver DonationController::cancelSubscription().
     */
    public function cancelPreapproval(\App\Models\DonationSubscription $subscription): bool
    {
        if (!$subscription->mp_preapproval_id) {
            return false;
        }

        $token = $this->getAccessToken();

        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->connectTimeout(5)
                ->put(
                    $this->buildUrl('preapproval/' . $subscription->mp_preapproval_id),
                    ['status' => 'cancelled']
                );
        } catch (ConnectionException $e) {
            Log::error('MercadoPago cancelPreapproval: timeout/conexão', [
                'subscription_id' => $subscription->id,
                'message' => $e->getMessage(),
            ]);
            return false;
        }

        if ($response->successful()) {
            return true;
        }

        Log::error('MercadoPago cancelPreapproval falhou', [
            'subscription_id' => $subscription->id,
            'http_status' => $response->status(),
        ]);

        return false;
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
                ->get($this->buildUrl('/v1/payments/' . $id));
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
