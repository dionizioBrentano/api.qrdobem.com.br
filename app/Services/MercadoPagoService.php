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
}
