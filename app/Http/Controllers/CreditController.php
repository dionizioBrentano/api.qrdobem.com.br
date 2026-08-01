<?php

namespace App\Http\Controllers;

use App\Models\CreditOrder;
use App\Models\CreditPricing;
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CreditController extends Controller
{
    /**
     * Retorna o preço e os limites configurados para compra de créditos.
     */
    public function pricing()
    {
        $pricing = CreditPricing::first();

        if ($pricing) {
            $unitPrice = $pricing->unit_price;
            $minQty = $pricing->min_quantity;
            $maxQty = $pricing->max_quantity;
        } else {
            $unitPrice = config('mercadopago.defaults.unit_price');
            $minQty = config('mercadopago.defaults.min_qty');
            $maxQty = config('mercadopago.defaults.max_qty');
        }

        return response()->json([
            'unit_price' => (float) $unitPrice,
            'min_quantity' => (int) $minQty,
            'max_quantity' => (int) $maxQty,
            'currency' => 'BRL',
        ]);
    }

    /**
     * Cria a intenção de compra e gera a preference no Mercado Pago.
     */
    public function checkout(Request $request, MercadoPagoService $mpService)
    {
        $tenant = $request->tenant;

        if (!$tenant || $tenant->profile_status !== 'active') {
            return response()->json([
                'error' => 'Perfil incompleto ou inativo.',
                'code' => $tenant && $tenant->profile_status ? 'PROFILE_INACTIVE' : 'PROFILE_INCOMPLETE'
            ], 403);
        }

        $pricing = CreditPricing::first();
        $unitPrice = $pricing ? $pricing->unit_price : config('mercadopago.defaults.unit_price');
        $minQty = $pricing ? $pricing->min_quantity : config('mercadopago.defaults.min_qty');
        $maxQty = $pricing ? $pricing->max_quantity : config('mercadopago.defaults.max_qty');

        $request->validate([
            'quantity' => "required|integer|min:{$minQty}|max:{$maxQty}",
        ]);

        $quantity = $request->quantity;
        $priceAmount = round($quantity * $unitPrice, 2);

        $organization = $tenant->organizations()->first();

        if (!$organization) {
            return response()->json(['error' => 'Nenhuma organização associada ao usuário.'], 400);
        }

        $order = CreditOrder::create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'price_amount' => $priceAmount,
            'status' => 'pending',
            'external_reference' => (string) Str::uuid(),
        ]);

        $preference = $mpService->createPreference($order);

        if (!$preference || !isset($preference['id'])) {
            return response()->json(['error' => 'Falha ao comunicar com o Mercado Pago.'], 500);
        }

        $order->update([
            'mp_preference_id' => $preference['id'],
        ]);

        $response = [
            'order_id' => $order->id,
            'external_reference' => $order->external_reference,
            'quantity' => $order->quantity,
            'unit_price' => (float) $order->unit_price,
            'price_amount' => (float) $order->price_amount,
            'init_point' => $preference['init_point'] ?? null,
        ];

        if (isset($preference['sandbox_init_point'])) {
            $response['sandbox_init_point'] = $preference['sandbox_init_point'];
        }

        return response()->json($response, 201);
    }
}
