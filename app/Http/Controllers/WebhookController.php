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

        // Assinatura validada. Conforme a documentação oficial do Mercado Pago,
        // eles esperam no máximo 22s pela confirmação de recebimento (200/201) —
        // e recomendam explicitamente buscar os detalhes do pagamento DEPOIS de
        // responder, não antes. Por isso respondemos já, e só então chamamos a
        // API deles de volta para buscar o pagamento e processar o pedido.
        $response = response()->json(['status' => 'received'], 200);
        $response->send();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        $this->processPayment((string) $dataId, $mpService);

        return $response;
    }

    /**
     * Busca o pagamento e processa o pedido. Roda DEPOIS que a resposta 200
     * já foi enviada ao Mercado Pago (ver comentário acima em mercadopago()).
     */
    private function processPayment(string $dataId, MercadoPagoService $mpService): void
    {
        $payment = $mpService->getPayment($dataId);

        if (!$payment) {
            Log::warning("MercadoPago Webhook: Payment {$dataId} not found.");
            return;
        }

        $externalReference = $payment['external_reference'] ?? null;
        if (!$externalReference) {
            Log::warning("MercadoPago Webhook: Payment {$dataId} has no external_reference.");
            return;
        }

        // A partir da Fase 4 o mesmo webhook atende dois fluxos distintos:
        // compra de créditos e DOAÇÃO. O prefixo do external_reference é o
        // que separa um do outro — `donation-<id>` é criado em
        // MercadoPagoService::createDonationPreference().
        //
        // Distinguir pelo prefixo, e não por consulta ao banco, evita que
        // uma doação seja procurada na tabela de pedidos de crédito (e
        // registrada como "não encontrada") a cada notificação.
        if (str_starts_with($externalReference, 'donation-')) {
            $this->processDonation($externalReference, $payment, $dataId);
            return;
        }

        $order = CreditOrder::where('external_reference', $externalReference)->first();

        if (!$order) {
            Log::warning("MercadoPago Webhook: Order not found for external_reference {$externalReference}.");
            return;
        }

        // Check if not approved
        $status = $payment['status'] ?? '';
        if ($status !== 'approved') {
            if (in_array($status, ['rejected', 'cancelled'])) {
                $order->update(['status' => $status]);
            }
            return;
        }

        // Idempotency check
        if ($order->status === 'approved') {
            return;
        }

        // Optional amount check (allow 0.05 variation)
        $paymentAmount = (float)($payment['transaction_amount'] ?? 0);
        $orderAmount = (float)$order->price_amount;

        if (abs($paymentAmount - $orderAmount) > 0.05) {
            Log::warning("MercadoPago Webhook: Payment {$dataId} amount mismatch. Expected: {$orderAmount}, Got: {$paymentAmount}");
        }

        CreditController::approveOrder($order, $dataId);
    }

    /**
     * Confirma uma DOAÇÃO e credita a causa (Fase 4, T4-R01/T4-R03).
     *
     * A gravação do `mp_payment_id` acontece ANTES do crédito, e a coluna é
     * única no banco. Assim, se o Mercado Pago reenviar a notificação — o
     * que ele faz —, a segunda tentativa esbarra na restrição e a causa não
     * é creditada duas vezes. Idempotência garantida pelo banco, não por
     * uma verificação que pode perder a corrida.
     */
    private function processDonation(string $externalReference, array $payment, string $dataId): void
    {
        $donationId = (int) str_replace('donation-', '', $externalReference);

        $donation = \App\Models\DonationCause::find($donationId);

        if (!$donation) {
            Log::warning("MercadoPago Webhook: Donation not found for {$externalReference}.");
            return;
        }

        $status = $payment['status'] ?? '';

        if ($status !== 'approved') {
            if (in_array($status, ['rejected', 'cancelled'], true)) {
                $donation->update(['status' => 'failed', 'mp_status' => $status]);
            }
            return;
        }

        if ($donation->isPaid()) {
            return;
        }

        $paymentAmount = (float) ($payment['transaction_amount'] ?? 0);
        $expected = (float) $donation->amount;

        // Divergência de valor é registrada, mas não bloqueia o crédito: o
        // dinheiro entrou de fato, e travar o repasse por 5 centavos de
        // arredondamento causaria mais dano do que a diferença.
        if (abs($paymentAmount - $expected) > 0.05) {
            Log::warning("MercadoPago Webhook: donation {$donationId} amount mismatch. Expected: {$expected}, Got: {$paymentAmount}");
        }

        try {
            $donation->update(['mp_payment_id' => $dataId]);
        } catch (\Throwable $e) {
            // Violação da unicidade = notificação repetida. Já processada.
            Log::info("MercadoPago Webhook: donation {$donationId} já processada (mp_payment_id duplicado).");
            return;
        }

        DonationCauseController::markAsPaid($donation, $status);
    }
}
