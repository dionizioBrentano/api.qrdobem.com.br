<?php

namespace App\Http\Controllers;

use App\Mail\DonationReceiptMail;
use App\Models\CauseProfile;
use App\Models\DonationCause;
use App\Models\DonationSubscription;
use App\Models\Space;
use App\Models\Tenant;
use App\Services\CpfIdentityService;
use App\Services\CpfValidator;
use App\Services\DonationFeeCalculator;
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * DonationCauseController — doação FINANCEIRA a uma causa (checkout). Fase 4.
 *
 * Bounded context separado do legado de comprovantes (DonationReceipt). Grava
 * na tabela `donation_causes` via model DonationCause.
 *
 * FLUXO DO CAPITAL (T4-R03)
 * O doador escolhe a causa; o dinheiro vai para a OSCIP gestora do QR do
 * Bem, que operacionaliza a distribuição. A causa escolhida é registrada
 * como DESTINAÇÃO, não como recebedora direta — é o que permite à OSCIP
 * responder pela prestação de contas e emitir o recibo dedutível.
 *
 * IDEMPOTÊNCIA DO WEBHOOK
 * `mp_payment_id` é único no banco. O Mercado Pago reenvia notificação, e
 * sem essa restrição a mesma doação seria creditada duas vezes na causa.
 * O crédito em `raised_amount` só acontece na transição para `paid`.
 *
 * RATEIO (taxa da OSCIP/plataforma)
 * O cálculo de quanto é taxa e quanto chega à causa vive num único lugar,
 * App\Services\DonationFeeCalculator, para que o preview e a criação da
 * doação nunca divirjam. A taxa incide sempre sobre o VALOR BRUTO. Não há
 * benefício de IRPF: a única modalidade fiscal ativa é doação com recibo da
 * OSCIP gestora.
 *
 * DOAR NÃO EXIGE CONTA (guest checkout)
 * A criação usa auth OPCIONAL (auth.firebase.optional): com Bearer válido, a
 * doação é atribuída ao tenant e usa os dados do perfil; sem token, o doador
 * se identifica na própria doação (nome, e-mail, CPF) e consente com o uso
 * dos dados (LGPD). É o MESMO cálculo e a MESMA persistência dos dois lados —
 * o guest não é um fluxo paralelo, é o mesmo com o tenant nulo.
 *
 * O CPF do guest é cifrado em repouso (cast `encrypted`) e indexado por blind
 * index (CpfIdentityService::hash) só para conciliação. Nunca é logado, nunca
 * volta em JSON, nunca vai para a query string.
 *
 * ENDPOINTS
 *   POST /donation-causes/preview   (público) detalha o rateio sem gravar nada
 *   POST /donation-causes           (auth opcional) cria a doação e o checkout
 *   GET  /donation-causes/mine       doações do usuário (exige conta)
 *   POST /donation-causes/subscribe  assinatura recorrente (exige conta)
 *   POST /donation-causes/{id}/cancel-subscription
 *   GET  /causes/{slug}/donations    (público) últimas doações da causa
 */
class DonationCauseController extends Controller
{
    public function __construct(private MercadoPagoService $mercadoPago)
    {
    }

    /**
     * POST /donation-causes/preview  — PÚBLICO
     *
     * Detalha o rateio SEM gravar nada: é a fonte que o front consome para
     * mostrar ao doador, antes de confirmar, quanto é taxa e quanto chega à
     * causa. Mesma conta que o store() usa, então o número exibido é o número
     * cobrado.
     *
     * Body: amount, cover_fees?, extra_platform_support?, payment_method?
     */
    public function preview(Request $request, DonationFeeCalculator $calculator)
    {
        $validated = $request->validate([
            'amount'                 => 'required|numeric|min:0.01',
            'cover_fees'             => 'sometimes|boolean',
            'extra_platform_support' => 'sometimes|numeric|min:0',
            // Aceito por paridade com o checkout; não altera o rateio. O custo
            // real do meio de pagamento só é conhecido depois da cobrança.
            'payment_method'         => 'sometimes|nullable|string|in:pix,credit_card,citizen_card',
        ]);

        try {
            $breakdown = $calculator->breakdown(
                (float) $validated['amount'],
                (bool) ($validated['cover_fees'] ?? false),
                0.0,
                (float) ($validated['extra_platform_support'] ?? 0),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($breakdown);
    }

    /**
     * POST /donation-causes  — auth OPCIONAL (doar não exige conta)
     *
     * Body (comum): amount, payment_method, cause_slug?, is_anonymous?,
     *   message?, cover_fees?, extra_platform_support?
     * Body (guest, sem token): + payer_name, payer_email, payer_cpf,
     *   consent_lgpd (obrigatório).
     */
    public function store(
        Request $request,
        DonationFeeCalculator $calculator,
        CpfValidator $cpfValidator,
        CpfIdentityService $cpfIdentity
    ) {
        // Auth opcional: com token válido o middleware preenche o tenant; sem
        // token, a doação é guest.
        //
        // BLINDAGEM: só aceitamos como autenticado um Tenant REAL vindo do
        // middleware. Sob auth opcional, sem token, `$request->tenant` cairia
        // no corpo da requisição — um guest poderia mandar "tenant" no JSON e
        // se passar por logado. O instanceof corta essa injeção.
        $tenant  = $request->tenant instanceof Tenant ? $request->tenant : null;
        $isGuest = !$tenant;

        $rules = [
            'amount'                 => 'required|numeric|min:1',
            'payment_method'         => 'required|string|in:pix,citizen_card',
            'cause_slug'             => 'sometimes|nullable|string',
            'is_anonymous'           => 'sometimes|boolean',
            'message'                => 'sometimes|nullable|string|max:500',
            'cover_fees'             => 'sometimes|boolean',
            'extra_platform_support' => 'sometimes|numeric|min:0',
        ];

        // Guest precisa se identificar (exigência dos meios de pagamento) e
        // consentir com o uso dos dados. Logado não repete nada disso: já veio
        // do perfil autenticado.
        if ($isGuest) {
            $rules['payer_name']   = 'required|string|max:255';
            $rules['payer_email']  = 'required|email|max:255';
            $rules['payer_cpf']    = 'required|string|max:20';
            $rules['consent_lgpd'] = 'accepted';
        }

        $validated = $request->validate($rules);

        if ($validated['payment_method'] === 'citizen_card') {
            return response()->json(['error' => 'Forma de pagamento indisponível no momento.'], 422);
        }

        // Identidade do guest: CPF validado pelo algoritmo já existente, depois
        // cifrado (cast) e reduzido a blind index para conciliação. O CPF em
        // claro não é guardado em variável além do necessário nem logado.
        $donorDocumentEncrypted = null;
        $donorDocumentHash      = null;
        $lgpdConsentAt          = null;

        if ($isGuest) {
            $cpfNormalized = preg_replace('/\D/', '', $validated['payer_cpf']);

            if (!$cpfValidator->isValid($cpfNormalized)) {
                return response()->json(['error' => 'CPF inválido.'], 422);
            }

            $donorDocumentEncrypted = $cpfNormalized; // o cast `encrypted` cifra ao gravar
            $donorDocumentHash      = $cpfIdentity->hash($cpfNormalized);
            $lgpdConsentAt          = now();
        }

        // Causa opcional: doação livre é caso legítimo — a OSCIP distribui
        // conforme necessidade.
        $causeSpace = null;

        if (!empty($validated['cause_slug'])) {
            $causeSpace = Space::where('slug', $validated['cause_slug'])
                ->where('type', Space::TYPE_CAUSE)
                ->first();

            if (!$causeSpace) {
                return response()->json(['error' => 'Causa não encontrada.'], 404);
            }
        }

        // Rateio canônico. `amount` recebe o TOTAL A PAGAR (o que o Mercado
        // Pago cobra e o que o webhook confere); o bruto fica em amount_gross
        // e o líquido da causa em amount_to_cause. Custo do meio de pagamento
        // é 0 aqui: só se conhece o valor real depois da cobrança.
        try {
            $breakdown = $calculator->breakdown(
                (float) $validated['amount'],
                (bool) ($validated['cover_fees'] ?? false),
                0.0,
                (float) ($validated['extra_platform_support'] ?? 0),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $donation = DonationCause::create([
            'public_token'             => Str::random(32),
            'cause_space_id'           => $causeSpace?->id,
            // Guest fica com tenant nulo — sem criar conta fantasma. Logado
            // é atribuído à conta e usa os dados do perfil.
            'donor_tenant_id'          => $tenant?->id,
            'donor_name'               => $isGuest ? $validated['payer_name']  : $tenant->name,
            'donor_email'              => $isGuest ? $validated['payer_email'] : $tenant->email,
            'donor_document_encrypted' => $donorDocumentEncrypted,
            'donor_document_hash'      => $donorDocumentHash,
            'lgpd_consent_at'          => $lgpdConsentAt,
            'amount'                   => $breakdown['total_to_pay'],
            'amount_gross'             => $breakdown['amount_gross'],
            'platform_fee_percent'     => $breakdown['platform_fee_percent'],
            'platform_fee_amount'      => $breakdown['platform_fee_amount'],
            'payment_fee_amount'       => $breakdown['payment_fee_amount'],
            'amount_to_cause'          => $breakdown['amount_to_cause'],
            'cover_fees'               => $breakdown['cover_fees'],
            'extra_platform_support'   => $breakdown['extra_platform_support'],
            'payment_method'           => $validated['payment_method'],
            'status'                   => 'pending',
            'is_anonymous'             => $validated['is_anonymous'] ?? false,
            'message'                  => $validated['message'] ?? null,
        ]);

        // A criação do PIX no Mercado Pago pode falhar por rede.
        // A doação já está gravada como `pending`, então o usuário pode
        // retomar — perder o registro seria pior que um pagamento pendente.
        try {
            $payment = $this->mercadoPago->createDonationPixPayment(
                $donation,
                $isGuest ? $validated['payer_email'] : $tenant->email,
                $isGuest ? $validated['payer_name'] : $tenant->name,
                $isGuest ? $validated['payer_cpf'] : null
            );

            if (!$payment) {
                throw new \Exception("Falha ao criar pagamento PIX no Mercado Pago");
            }

            $donation->update([
                'mp_payment_id' => $payment['id'] ?? null,
            ]);

            return response()->json([
                'message'        => 'Doação iniciada.',
                'donation_id'    => $donation->id,
                'public_token'   => $donation->public_token,
                'status_path'    => '/doacao/status/' . $donation->public_token,
                'status'         => $donation->status,
                'payment_method' => $donation->payment_method,
                'pix' => [
                    'qr_code'        => $payment['point_of_interaction']['transaction_data']['qr_code'] ?? null,
                    'qr_code_base64' => $payment['point_of_interaction']['transaction_data']['qr_code_base64'] ?? null,
                ],
            ], 201);
        } catch (\Throwable $e) {
            Log::error('DonationCauseController: falha ao criar pagamento PIX', [
                'donation_id' => $donation->id,
                'error'       => $e->getMessage(),
            ]);

            return response()->json([
                'error'       => 'Não foi possível iniciar o pagamento agora. Tente de novo.',
                'donation_id' => $donation->id,
            ], 503);
        }
    }

    /**
     * POST /donation-causes/card — auth OPCIONAL
     * Processa o Checkout transparente do Cartão (Payment Brick).
     */
    public function storeCard(
        Request $request,
        DonationFeeCalculator $calculator,
        CpfValidator $cpfValidator,
        CpfIdentityService $cpfIdentity
    ) {
        $tenant  = $request->tenant instanceof Tenant ? $request->tenant : null;
        $isGuest = !$tenant;

        $rules = [
            'amount'                 => 'required|numeric|min:1',
            'payment_method'         => 'required|string|in:credit_card',
            'cause_slug'             => 'sometimes|nullable|string',
            'is_anonymous'           => 'sometimes|boolean',
            'message'                => 'sometimes|nullable|string|max:500',
            'cover_fees'             => 'sometimes|boolean',
            'extra_platform_support' => 'sometimes|numeric|min:0',
            
            // Dados do Brick de cartão
            'token'                  => 'required|string',
            'payment_method_id'      => 'required|string',
            'installments'           => 'required|integer|min:1',
            'payer.email'            => 'required|email',
            'payer.identification.type'   => 'sometimes|nullable|string',
            'payer.identification.number' => 'sometimes|nullable|string',
            'issuer_id'              => 'sometimes|nullable|string',
        ];

        if ($isGuest) {
            $rules['payer_name']   = 'required|string|max:255';
            $rules['payer_email']  = 'required|email|max:255';
            $rules['payer_cpf']    = 'required|string|max:20';
            $rules['consent_lgpd'] = 'accepted';
        }

        $validated = $request->validate($rules);

        $donorDocumentEncrypted = null;
        $donorDocumentHash      = null;
        $lgpdConsentAt          = null;

        if ($isGuest) {
            $cpfNormalized = preg_replace('/\D/', '', $validated['payer_cpf']);

            if (!$cpfValidator->isValid($cpfNormalized)) {
                return response()->json(['error' => 'CPF inválido.'], 422);
            }

            $donorDocumentEncrypted = $cpfNormalized;
            $donorDocumentHash      = $cpfIdentity->hash($cpfNormalized);
            $lgpdConsentAt          = now();
        }

        $causeSpace = null;
        if (!empty($validated['cause_slug'])) {
            $causeSpace = Space::where('slug', $validated['cause_slug'])
                ->where('type', Space::TYPE_CAUSE)
                ->first();

            if (!$causeSpace) {
                return response()->json(['error' => 'Causa não encontrada.'], 404);
            }
        }

        try {
            $breakdown = $calculator->breakdown(
                (float) $validated['amount'],
                (bool) ($validated['cover_fees'] ?? false),
                0.0,
                (float) ($validated['extra_platform_support'] ?? 0),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $donation = DonationCause::create([
            'public_token'             => Str::random(32),
            'cause_space_id'           => $causeSpace?->id,
            'donor_tenant_id'          => $tenant?->id,
            'donor_name'               => $isGuest ? $validated['payer_name']  : $tenant->name,
            'donor_email'              => $isGuest ? $validated['payer_email'] : $tenant->email,
            'donor_document_encrypted' => $donorDocumentEncrypted,
            'donor_document_hash'      => $donorDocumentHash,
            'lgpd_consent_at'          => $lgpdConsentAt,
            'amount'                   => $breakdown['total_to_pay'],
            'amount_gross'             => $breakdown['amount_gross'],
            'platform_fee_percent'     => $breakdown['platform_fee_percent'],
            'platform_fee_amount'      => $breakdown['platform_fee_amount'],
            'payment_fee_amount'       => $breakdown['payment_fee_amount'],
            'amount_to_cause'          => $breakdown['amount_to_cause'],
            'cover_fees'               => $breakdown['cover_fees'],
            'extra_platform_support'   => $breakdown['extra_platform_support'],
            'payment_method'           => 'credit_card',
            'status'                   => 'pending',
            'is_anonymous'             => $validated['is_anonymous'] ?? false,
            'message'                  => $validated['message'] ?? null,
        ]);

        try {
            $payment = $this->mercadoPago->createDonationCardPayment(
                $donation,
                $validated['payer']['email'],
                $validated['token'],
                $validated['payment_method_id'],
                $validated['installments'],
                $validated['issuer_id'] ?? null,
                $validated['payer']['identification']['type'] ?? null,
                $validated['payer']['identification']['number'] ?? null
            );

            if (!$payment) {
                throw new \Exception("Falha ao processar cartão no Mercado Pago");
            }

            $donation->update([
                'mp_payment_id' => $payment['id'] ?? null,
            ]);

            if (($payment['status'] ?? '') === 'approved') {
                self::markAsPaid($donation, $payment['status_detail'] ?? null);
                
                return response()->json([
                    'message'      => 'Doação concluída.',
                    'donation_id'  => $donation->id,
                    'public_token' => $donation->public_token,
                    'status_path'  => '/doacao/status/' . $donation->public_token,
                    'status'       => 'paid',
                ], 201);
            }

            // Em processamento ou recusado
            return response()->json([
                'message'      => 'Pagamento ' . ($payment['status'] ?? 'pending'),
                'donation_id'  => $donation->id,
                'public_token' => $donation->public_token,
                'status_path'  => '/doacao/status/' . $donation->public_token,
                'status'       => 'pending',
            ], 201);
        } catch (\Throwable $e) {
            Log::error('DonationCauseController: falha ao processar cartão', [
                'donation_id' => $donation->id,
                'error'       => $e->getMessage(),
            ]);

            return response()->json([
                'error'       => 'Não foi possível processar o cartão agora. Tente de novo.',
                'donation_id' => $donation->id,
            ], 503);
        }
    }

    /** GET /donation-causes/mine */
    public function mine(Request $request)
    {
        $donations = DonationCause::with('cause')
            ->where('donor_tenant_id', $request->tenant->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'donations' => $donations->map(fn (DonationCause $d) => [
                'id'             => $d->id,
                'amount'         => $d->amount,
                'status'         => $d->status,
                'payment_method' => $d->payment_method,
                'cause'          => $d->cause?->only(['name', 'slug']),
                'paid_at'        => $d->paid_at,
                'created_at'     => $d->created_at,
            ])->values(),
            'total_donated' => $donations->where('status', 'paid')->sum('amount'),
        ]);
    }

    /**
     * POST /donation-causes/subscribe  (T4-R04)
     * Body: amount, cause_slug?, frequency?
     */
    public function subscribe(Request $request)
    {
        $tenant = $request->tenant;

        $validated = $request->validate([
            'amount'     => 'required|numeric|min:1',
            'cause_slug' => 'sometimes|nullable|string',
            'frequency'  => 'sometimes|string|in:monthly',
        ]);

        $causeSpace = !empty($validated['cause_slug'])
            ? Space::where('slug', $validated['cause_slug'])->where('type', Space::TYPE_CAUSE)->first()
            : null;

        $subscription = DonationSubscription::create([
            'cause_space_id'  => $causeSpace?->id,
            'donor_tenant_id' => $tenant->id,
            'amount'          => $validated['amount'],
            'frequency'       => $validated['frequency'] ?? 'monthly',
            'status'          => 'pending',
        ]);

        try {
            $preapproval = $this->mercadoPago->createPreapproval($subscription);

            $subscription->update([
                'mp_preapproval_id' => $preapproval['id'] ?? null,
            ]);

            return response()->json([
                'message'         => 'Assinatura criada. Conclua a autorização no Mercado Pago.',
                'subscription_id' => $subscription->id,
                'checkout'        => $preapproval,
            ], 201);
        } catch (\Throwable $e) {
            Log::error('DonationCauseController: falha ao criar assinatura', [
                'subscription_id' => $subscription->id,
                'error'           => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Não foi possível criar a assinatura agora.',
            ], 503);
        }
    }

    /** POST /donation-causes/{id}/cancel-subscription */
    public function cancelSubscription(Request $request, $subscriptionId)
    {
        $subscription = DonationSubscription::where('id', $subscriptionId)
            ->where('donor_tenant_id', $request->tenant->id)
            ->first();

        if (!$subscription) {
            return response()->json(['error' => 'Assinatura não encontrada.'], 404);
        }

        try {
            $this->mercadoPago->cancelPreapproval($subscription);
        } catch (\Throwable $e) {
            // Falha na API do MP não pode impedir o cancelamento local: o
            // doador pediu para parar, e deixar como ativo seria pior.
            // O estado divergente é reconciliado pelo webhook.
            Log::warning('DonationCauseController: falha ao cancelar no MP', [
                'subscription_id' => $subscription->id,
                'error'           => $e->getMessage(),
            ]);
        }

        $subscription->update([
            'status'       => 'cancelled',
            'cancelled_at' => now(),
        ]);

        return response()->json(['message' => 'Assinatura cancelada.']);
    }

    /**
     * GET /causes/{slug}/donations  — PÚBLICO
     * Últimas doações da causa. Prova social do lado do dinheiro.
     */
    public function publicList(Request $request, $slug)
    {
        $space = Space::where('slug', $slug)->where('type', Space::TYPE_CAUSE)->first();

        if (!$space) {
            return response()->json(['error' => 'Causa não encontrada.'], 404);
        }

        $donations = DonationCause::where('cause_space_id', $space->id)
            ->where('status', 'paid')
            ->orderByDesc('paid_at')
            ->limit(30)
            ->get();

        return response()->json([
            // `publicName()` respeita o anonimato; o e-mail está em $hidden
            // e não vaza nem por engano na serialização.
            'donations' => $donations->map(fn (DonationCause $d) => [
                'name'    => $d->publicName(),
                'amount'  => $d->amount,
                'message' => $d->message,
                'paid_at' => $d->paid_at,
            ])->values(),
            'total' => $donations->sum('amount'),
        ]);
    }

    /**
     * Confirma o pagamento e credita a causa.
     *
     * Chamado pelo WebhookController. Fica aqui, e não no webhook, para que
     * exista UM lugar só onde a doação vira `paid` — duas rotas gravando o
     * mesmo estado é como se produz doação contada em dobro.
     */
    public static function markAsPaid(DonationCause $donation, ?string $mpStatus = null): void
    {
        // Idempotência: já paga, não credita de novo. O Mercado Pago
        // reenvia notificação, e sem esta guarda a causa seria creditada
        // a cada reenvio.
        if ($donation->isPaid()) {
            return;
        }

        DB::transaction(function () use ($donation, $mpStatus) {
            $donation->update([
                'status'    => 'paid',
                'mp_status' => $mpStatus,
                'paid_at'   => now(),
            ]);

            if (!$donation->cause_space_id) {
                return;
            }

            // A causa recebe o LÍQUIDO: bruto menos a taxa da plataforma e o
            // custo do meio de pagamento. Creditar o `amount` (que é o total
            // pago, podendo incluir taxas quando cover_fees) inflaria o
            // arrecadado. Doações antigas, sem rateio gravado, caem no
            // `amount` — que para elas equivale ao próprio valor doado.
            $creditToCause = $donation->amount_to_cause ?? $donation->amount;

            // `increment` em vez de ler-somar-gravar: duas confirmações
            // simultâneas se perderiam no caminho de leitura.
            CauseProfile::where('space_id', $donation->cause_space_id)
                ->increment('raised_amount', (float) $creditToCause);
        });

        // Recibo/confirmação ao doador — logado ou guest. Fica FORA da
        // transação e num try/catch: um e-mail que falha não pode reverter
        // uma doação já paga nem travar o webhook do Mercado Pago. Este ponto
        // só é alcançado na primeira transição para `paid` (a guarda de
        // idempotência acima já retornou nas notificações repetidas).
        if ($donation->donor_email) {
            try {
                Mail::to($donation->donor_email)->queue(new DonationReceiptMail($donation));
            } catch (\Throwable $e) {
                Log::warning('DonationCauseController: falha ao enviar recibo da doação', [
                    'donation_id' => $donation->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Consulta pública de status sem PII.
     */
    public function publicStatus(string $token)
    {
        $donation = DonationCause::with('cause')->where('public_token', $token)->first();

        if (!$donation) {
            return response()->json(['error' => 'Doação não encontrada.'], 404);
        }

        return response()->json([
            'status'               => $donation->status,
            'amount_gross'         => $donation->amount_gross,
            'platform_fee_percent' => $donation->platform_fee_percent,
            'platform_fee_amount'  => $donation->platform_fee_amount,
            'payment_fee_amount'   => $donation->payment_fee_amount,
            'amount_to_cause'      => $donation->amount_to_cause,
            'cover_fees'           => $donation->cover_fees,
            'cause'                => $donation->cause ? [
                'name' => $donation->cause->name,
                'slug' => $donation->cause->slug,
            ] : null,
            'paid_at'              => $donation->paid_at ? $donation->paid_at->format('Y-m-d H:i:s') : null,
            'created_at'           => $donation->created_at ? $donation->created_at->format('Y-m-d H:i:s') : null,
        ]);
    }
}
