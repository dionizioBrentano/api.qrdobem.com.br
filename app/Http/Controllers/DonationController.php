<?php

namespace App\Http\Controllers;

use App\Models\CauseProfile;
use App\Models\Donation;
use App\Models\DonationSubscription;
use App\Models\Space;
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DonationController — doações à causa. Fase 4, T4-R01 a T4-R04.
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
 * ENDPOINTS
 *   POST /donations              cria a doação e a preferência de pagamento
 *   GET  /donations/mine         doações do usuário
 *   POST /donations/subscribe    assinatura recorrente
 *   POST /donations/{id}/cancel-subscription
 *   GET  /causes/{slug}/donations  (público) últimas doações da causa
 */
class DonationController extends Controller
{
    public function __construct(private MercadoPagoService $mercadoPago)
    {
    }

    /**
     * POST /donations
     * Body: amount, payment_method, cause_slug?, is_anonymous?, message?
     */
    public function store(Request $request)
    {
        $tenant = $request->tenant;

        $validated = $request->validate([
            'amount'         => 'required|numeric|min:1',
            'payment_method' => 'required|string|in:pix,credit_card,citizen_card',
            'cause_slug'     => 'sometimes|nullable|string',
            'is_anonymous'   => 'sometimes|boolean',
            'message'        => 'sometimes|nullable|string|max:500',
        ]);

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

        $donation = Donation::create([
            'cause_space_id'  => $causeSpace?->id,
            'donor_tenant_id' => $tenant->id,
            'donor_name'      => $tenant->name,
            'donor_email'     => $tenant->email,
            'amount'          => $validated['amount'],
            'payment_method'  => $validated['payment_method'],
            'status'          => 'pending',
            'is_anonymous'    => $validated['is_anonymous'] ?? false,
            'message'         => $validated['message'] ?? null,
        ]);

        // A criação da preferência no Mercado Pago pode falhar por rede.
        // A doação já está gravada como `pending`, então o usuário pode
        // retomar — perder o registro seria pior que um pagamento pendente.
        try {
            $preference = $this->mercadoPago->createDonationPreference($donation);

            $donation->update([
                'mp_preference_id' => $preference['id'] ?? null,
            ]);

            return response()->json([
                'message'    => 'Doação iniciada.',
                'donation_id' => $donation->id,
                'checkout'   => $preference,
            ], 201);
        } catch (\Throwable $e) {
            Log::error('DonationController: falha ao criar preferência', [
                'donation_id' => $donation->id,
                'error'       => $e->getMessage(),
            ]);

            return response()->json([
                'error'       => 'Não foi possível iniciar o pagamento agora. Tente de novo.',
                'donation_id' => $donation->id,
            ], 503);
        }
    }

    /** GET /donations/mine */
    public function mine(Request $request)
    {
        $donations = Donation::with('cause')
            ->where('donor_tenant_id', $request->tenant->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'donations' => $donations->map(fn (Donation $d) => [
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
     * POST /donations/subscribe  (T4-R04)
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
            Log::error('DonationController: falha ao criar assinatura', [
                'subscription_id' => $subscription->id,
                'error'           => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Não foi possível criar a assinatura agora.',
            ], 503);
        }
    }

    /** POST /donations/{id}/cancel-subscription */
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
            Log::warning('DonationController: falha ao cancelar no MP', [
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

        $donations = Donation::where('cause_space_id', $space->id)
            ->where('status', 'paid')
            ->orderByDesc('paid_at')
            ->limit(30)
            ->get();

        return response()->json([
            // `publicName()` respeita o anonimato; o e-mail está em $hidden
            // e não vaza nem por engano na serialização.
            'donations' => $donations->map(fn (Donation $d) => [
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
    public static function markAsPaid(Donation $donation, ?string $mpStatus = null): void
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

            // `increment` em vez de ler-somar-gravar: duas confirmações
            // simultâneas se perderiam no caminho de leitura.
            CauseProfile::where('space_id', $donation->cause_space_id)
                ->increment('raised_amount', (float) $donation->amount);
        });
    }
}
