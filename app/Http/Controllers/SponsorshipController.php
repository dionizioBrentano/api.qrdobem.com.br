<?php

namespace App\Http\Controllers;

use App\Models\Beneficiary;
use App\Models\DonationSubscription;
use App\Models\Sponsorship;
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SponsorshipController — apadrinhamento digital via QR.
 * Fase 6, T2-R06 do PLANO_TRILHAS_2026-08.md.
 *
 * DIFERENÇA PARA A DOAÇÃO À CAUSA
 * Na doação, o recurso vai para a OSCIP e é distribuído conforme
 * necessidade. No apadrinhamento, o padrinho acompanha UMA pessoa — e é
 * esse vínculo que dá sentido à relação e sustenta a recorrência.
 *
 * A cobrança reaproveita `donation_subscriptions`: é o mesmo Preapproval do
 * Mercado Pago. Criar um segundo mecanismo de assinatura para a mesma
 * mecânica só multiplicaria os pontos de falha na conciliação.
 *
 * ENDPOINTS
 *   POST /b/{unique_code}/sponsor    apadrinhar (autenticado)
 *   GET  /sponsorships/mine          meus apadrinhamentos
 *   POST /sponsorships/{id}/end      encerrar
 */
class SponsorshipController extends Controller
{
    public function __construct(private MercadoPagoService $mercadoPago)
    {
    }

    /**
     * POST /b/{unique_code}/sponsor  { monthly_amount }
     *
     * O padrinho chega pelo QR do beneficiário — que é o "apadrinhamento
     * digital via QR Code" do requisito.
     */
    public function store(Request $request, $uniqueCode)
    {
        $tenant = $request->tenant;

        $beneficiary = Beneficiary::where('unique_code', $uniqueCode)
            ->where('status', 'active')
            ->first();

        if (!$beneficiary) {
            return response()->json(['error' => 'Beneficiário não encontrado.'], 404);
        }

        $validated = $request->validate([
            'monthly_amount' => 'required|numeric|min:1',
        ]);

        // Já apadrinha: o unique no banco barraria, mas com uma mensagem
        // que não serve ao usuário — e o pior, depois de já ter criado a
        // assinatura no Mercado Pago.
        $existing = Sponsorship::where('beneficiary_id', $beneficiary->id)
            ->where('sponsor_tenant_id', $tenant->id)
            ->where('status', 'active')
            ->first();

        if ($existing) {
            return response()->json([
                'error' => 'Você já apadrinha esta pessoa.',
                'code'  => 'ALREADY_SPONSORING',
            ], 422);
        }

        $result = DB::transaction(function () use ($beneficiary, $tenant, $validated) {
            $subscription = DonationSubscription::create([
                'cause_space_id'  => $beneficiary->space_id,
                'donor_tenant_id' => $tenant->id,
                'amount'          => $validated['monthly_amount'],
                'frequency'       => 'monthly',
                'status'          => 'pending',
            ]);

            $sponsorship = Sponsorship::create([
                'beneficiary_id'    => $beneficiary->id,
                'sponsor_tenant_id' => $tenant->id,
                'subscription_id'   => $subscription->id,
                'monthly_amount'    => $validated['monthly_amount'],
                'status'            => 'active',
                'started_at'        => now(),
            ]);

            return [$subscription, $sponsorship];
        });

        [$subscription, $sponsorship] = $result;

        try {
            $preapproval = $this->mercadoPago->createPreapproval($subscription);
            $subscription->update(['mp_preapproval_id' => $preapproval['id'] ?? null]);

            return response()->json([
                'message'     => 'Apadrinhamento criado. Conclua a autorização no Mercado Pago.',
                'sponsorship' => ['id' => $sponsorship->id],
                'checkout'    => $preapproval,
            ], 201);
        } catch (\Throwable $e) {
            Log::error('SponsorshipController: falha ao criar assinatura', [
                'sponsorship_id' => $sponsorship->id,
                'error'          => $e->getMessage(),
            ]);

            // O vínculo fica gravado: o padrinho pode retomar o pagamento
            // depois, sem refazer o cadastro.
            return response()->json([
                'error'          => 'Não foi possível abrir o pagamento agora.',
                'sponsorship_id' => $sponsorship->id,
            ], 503);
        }
    }

    /** GET /sponsorships/mine */
    public function mine(Request $request)
    {
        $sponsorships = Sponsorship::with(['beneficiary', 'subscription'])
            ->where('sponsor_tenant_id', $request->tenant->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'sponsorships' => $sponsorships->map(fn (Sponsorship $s) => [
                'id'             => $s->id,
                // Só o primeiro nome: a página do padrinho não é lugar de
                // expor o nome completo de quem recebe.
                'beneficiary'    => explode(' ', (string) $s->beneficiary?->name)[0] ?? '',
                'monthly_amount' => $s->monthly_amount,
                'status'         => $s->status,
                'started_at'     => $s->started_at,
                'payment_status' => $s->subscription?->status,
            ])->values(),
            'total_monthly' => $sponsorships->where('status', 'active')->sum('monthly_amount'),
        ]);
    }

    /** POST /sponsorships/{id}/end */
    public function end(Request $request, $sponsorshipId)
    {
        $sponsorship = Sponsorship::where('id', $sponsorshipId)
            ->where('sponsor_tenant_id', $request->tenant->id)
            ->first();

        if (!$sponsorship) {
            return response()->json(['error' => 'Apadrinhamento não encontrado.'], 404);
        }

        if ($sponsorship->subscription) {
            try {
                $this->mercadoPago->cancelPreapproval($sponsorship->subscription);
            } catch (\Throwable $e) {
                // Mesma regra da doação recorrente: falha no Mercado Pago
                // não impede o encerramento local. O padrinho pediu para
                // parar; o webhook reconcilia depois.
                Log::warning('SponsorshipController: falha ao cancelar no MP', [
                    'sponsorship_id' => $sponsorship->id,
                    'error'          => $e->getMessage(),
                ]);
            }

            $sponsorship->subscription->update([
                'status'       => 'cancelled',
                'cancelled_at' => now(),
            ]);
        }

        $sponsorship->update([
            'status'   => 'ended',
            'ended_at' => now(),
        ]);

        return response()->json(['message' => 'Apadrinhamento encerrado.']);
    }
}
