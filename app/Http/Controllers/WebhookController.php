<?php

namespace App\Http\Controllers;

use App\Models\CreditBatch;
use App\Models\CreditOrder;
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\CreditController;

class WebhookController extends Controller
{
    public function mercadopago(Request $request, MercadoPagoService $mpService)
    {
        $secret = config('mercadopago.webhook_secret');
        if (empty($secret)) {
            Log::error('Mercado Pago webhook_secret is empty.');
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $signatureHeader = $request->header('x-signature');
        $requestId = $request->header('x-request-id');

        if (!$signatureHeader || !$requestId) {
            return response()->json(['error' => 'Missing headers'], 401);
        }

        // Parse x-signature (e.g. ts=...,v1=...)
        $parts = explode(',', $signatureHeader);
        $ts = null;
        $v1 = null;

        foreach ($parts as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2) {
                if ($kv[0] === 'ts') $ts = $kv[1];
                if ($kv[0] === 'v1') $v1 = $kv[1];
            }
        }

        if (!$ts || !$v1) {
            return response()->json(['error' => 'Invalid signature format'], 401);
        }

        // Anti-replay (900 seconds = 15 minutes)
        if (abs(time() - (int)$ts) > 900) {
            return response()->json(['error' => 'Request too old'], 401);
        }

        $dataId = $request->query('data_id') ?? $request->query('data.id') ?? $request->input('data.id');
        if (!$dataId) {
            return response()->json(['error' => 'Missing data.id'], 400);
        }

        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $hash = hash_hmac('sha256', $manifest, $secret);

        if (!hash_equals($hash, $v1)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // Validated! Let's get the payment details.
        $payment = $mpService->getPayment((string)$dataId);

        if (!$payment) {
            Log::warning("MercadoPago Webhook: Payment {$dataId} not found.");
            return response()->json(['status' => 'Payment not found'], 200);
        }

        $externalReference = $payment['external_reference'] ?? null;
        if (!$externalReference) {
            Log::warning("MercadoPago Webhook: Payment {$dataId} has no external_reference.");
            return response()->json(['status' => 'Ignored, missing external_reference'], 200);
        }

        $order = CreditOrder::where('external_reference', $externalReference)->first();

        if (!$order) {
            Log::warning("MercadoPago Webhook: Order not found for external_reference {$externalReference}.");
            return response()->json(['status' => 'Order not found'], 200);
        }

        // Check if not approved
        $status = $payment['status'] ?? '';
        if ($status !== 'approved') {
            if (in_array($status, ['rejected', 'cancelled'])) {
                $order->update(['status' => $status]);
            }
            return response()->json(['status' => 'Payment not approved'], 200);
        }

        // Idempotency check
        if ($order->status === 'approved') {
            return response()->json(['status' => 'Already processed'], 200);
        }

        // Optional amount check (allow 0.05 variation)
        $paymentAmount = (float)($payment['transaction_amount'] ?? 0);
        $orderAmount = (float)$order->price_amount;

        if (abs($paymentAmount - $orderAmount) > 0.05) {
            Log::warning("MercadoPago Webhook: Payment {$dataId} amount mismatch. Expected: {$orderAmount}, Got: {$paymentAmount}");
            // We can still continue or reject, but instructions said optional check, we'll log it.
        }

        CreditController::approveOrder($order, (string)$dataId);

        return response()->json(['status' => 'ok'], 200);
    }
}
