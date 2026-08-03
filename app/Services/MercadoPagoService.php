<?php

namespace App\Services;

use App\Models\CreditOrder;
use Illuminate\Support\Facades\Http;

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

        $response = Http::withToken($token)
            ->post('https://api.mercadopago.com/checkout/preferences', $payload);

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

        $response = Http::withToken($token)
            ->withHeaders([
                'X-Idempotency-Key' => $order->external_reference,
            ])
            ->post('https://api.mercadopago.com/v1/payments', $payload);

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
        ?string $issuerId = null
    ): ?array {
        $accessToken = $this->getAccessToken();

        $payload = [
            'transaction_amount' => (float) $order->price_amount,
            'description' => 'Créditos QR do Bem',
            'payment_method_id' => $paymentMethodId,
            'token' => $token,
            'installments' => $installments,
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

        if ($issuerId) {
            $payload['issuer_id'] = $issuerId;
        }

        $response = Http::withToken($accessToken)
            ->withHeaders([
                'X-Idempotency-Key' => $order->external_reference,
            ])
            ->post('https://api.mercadopago.com/v1/payments', $payload);

        if ($response->successful()) {
            return $response->json();
        }

        return null;
    }

    /**
     * Busca os detalhes de um pagamento no Mercado Pago.
     */
    public function getPayment(string $id): ?array
    {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->get("https://api.mercadopago.com/v1/payments/{$id}");

        if ($response->successful()) {
            return $response->json();
        }

        return null;
    }
}
