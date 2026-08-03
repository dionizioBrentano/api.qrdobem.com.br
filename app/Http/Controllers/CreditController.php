<?php

namespace App\Http\Controllers;

use App\Models\CreditBatch;
use App\Models\CreditOrder;
use App\Models\CreditPricing;
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CreditController extends Controller
{
    /**
     * Retorna a configuração pública do Mercado Pago para o front-end.
     */
    public function publicConfig(MercadoPagoService $mpService)
    {
        return response()->json([
            'public_key' => $mpService->getPublicKey(),
            'locale' => 'pt-BR',
            'mode' => config('mercadopago.mode'),
        ]);
    }

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

        if (!$tenant->email) {
            return response()->json(['error' => 'Email do perfil é obrigatório para pagamento via PIX.'], 422);
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

        $payment = $mpService->createPixPayment($order, $tenant->email);

        if (!$payment || !isset($payment['id'])) {
            Log::error('Falha ao criar pagamento PIX no Mercado Pago', ['external_reference' => $order->external_reference]);
            return response()->json(['error' => 'Falha ao processar pagamento com Mercado Pago.'], 502);
        }

        $order->update([
            'mp_payment_id' => (string) $payment['id'],
        ]);

        $transactionData = $payment['point_of_interaction']['transaction_data'] ?? [];

        $response = [
            'order_id' => $order->id,
            'external_reference' => $order->external_reference,
            'quantity' => $order->quantity,
            'unit_price' => (float) $order->unit_price,
            'price_amount' => (float) $order->price_amount,
            'status' => 'pending',
            'payment_method' => 'pix',
            'mp_payment_id' => $payment['id'],
            'pix' => [
                'qr_code' => $transactionData['qr_code'] ?? null,
                'qr_code_base64' => $transactionData['qr_code_base64'] ?? null,
                'ticket_url' => $transactionData['ticket_url'] ?? null,
            ]
        ];

        return response()->json($response, 201);
    }

    /**
     * Retorna o status de uma ordem de crédito.
     */
    public function showOrder(Request $request, $id)
    {
        $tenant = $request->tenant;
        $order = CreditOrder::where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (!$order) {
            return response()->json(['error' => 'Ordem não encontrada.'], 404);
        }

        return response()->json([
            'id' => $order->id,
            'status' => $order->status,
            'quantity' => $order->quantity,
            'price_amount' => (float) $order->price_amount,
            'mp_payment_id' => $order->mp_payment_id,
        ]);
    }

    /**
     * Cria um pagamento com Cartão via Checkout API (Payment Brick).
     */
    public function checkoutCard(Request $request, MercadoPagoService $mpService)
    {
        $tenant = $request->tenant;

        if (!$tenant || $tenant->profile_status !== 'active') {
            return response()->json([
                'error' => 'Perfil incompleto ou inativo.',
                'code' => $tenant && $tenant->profile_status ? 'PROFILE_INACTIVE' : 'PROFILE_INCOMPLETE'
            ], 403);
        }

        $request->validate([
            'quantity' => 'required|integer|min:1',
            'token' => 'required|string',
            'payment_method_id' => 'required|string',
            'payer_email' => 'nullable|email',
            'identification_type' => 'nullable|string',
            'identification_number' => 'nullable|string',
        ]);

        $payerEmail = $request->payer_email ?? $tenant->email;
        if (!$payerEmail) {
            return response()->json(['error' => 'Email do pagador é obrigatório.'], 422);
        }

        // O Brick já coleta o CPF do pagador (obrigatório no Brasil). Se por algum
        // motivo não vier, cai pro CPF já cadastrado no perfil do tenant.
        $identificationType = $request->identification_type ?? 'CPF';
        $identificationNumber = $request->identification_number ?? $tenant->cpf;

        if (!$identificationNumber) {
            return response()->json([
                'error' => 'CPF do pagador é obrigatório para pagamento com cartão.',
                'code' => 'IDENTIFICATION_REQUIRED',
            ], 422);
        }

        $pricing = CreditPricing::first();
        $unitPrice = $pricing ? $pricing->unit_price : config('mercadopago.defaults.unit_price');
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

        $payment = $mpService->createCardPayment(
            $order,
            $payerEmail,
            $request->token,
            $request->payment_method_id,
            $request->installments ?? 1,
            $request->issuer_id,
            $identificationType,
            $identificationNumber
        );

        if (!$payment || !isset($payment['id'])) {
            Log::error('Falha ao criar pagamento Cartão no Mercado Pago', ['external_reference' => $order->external_reference]);
            return response()->json([
                'error' => 'Falha ao processar pagamento com Cartão.',
                // DEBUG TEMPORÁRIO — remover depois de identificar a causa.
                'mp_debug' => $payment['_body'] ?? null,
                'env_debug' => $mpService->getEnvironmentInfo(),
            ], 502);
        }

        $status = $payment['status'];
        
        $order->update([
            'mp_payment_id' => (string) $payment['id'],
            'status' => $status === 'approved' ? 'pending' : $status, // approveOrder fará o update para approved
        ]);

        if ($status === 'approved') {
            self::approveOrder($order, (string) $payment['id']);
        }

        return response()->json([
            'order_id' => $order->id,
            'status' => $order->status,
            'mp_payment_id' => $payment['id'],
            'message' => $payment['status_detail'] ?? '',
        ], 201);
    }

    /**
     * Extrai a lógica de aprovação para ser reutilizada pelo Webhook e pelo checkout síncrono.
     */
    public static function approveOrder(CreditOrder $order, string $mpPaymentId)
    {
        if ($order->status === 'approved') return;

        DB::transaction(function () use ($order, $mpPaymentId) {
            $order->update([
                'status' => 'approved',
                'mp_payment_id' => $mpPaymentId,
            ]);

            CreditBatch::create([
                'organization_id' => $order->organization_id,
                'creator_tenant_id' => $order->tenant_id,
                'amount_total' => $order->quantity,
                'amount_available' => $order->quantity,
                'status' => 'active',
                'source' => 'mercadopago',
                'expires_at' => null,
            ]);
        });
    }
}
