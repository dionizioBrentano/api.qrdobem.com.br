<?php

namespace App\Services;

use App\Models\CreditBatch;
use App\Models\EntityConversation;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Créditos de brinde do funil Benfeitor -> Tutor.
 *
 * Degrau 1: quem cria conta a partir de uma conversa ganha 1 QR por 3 meses.
 * Degrau 2: quando o tutor marca aquela conversa como resolvida, o mesmo
 * benfeitor ganha mais 1 QR, válido por 1 ano.
 *
 * A idempotência segue o mesmo padrão de OnboardingCreditService: existência de
 * um batch com o `source` correspondente na organização.
 */
class BenefactorCreditService
{
    public const SOURCE_SIGNUP = 'benefactor_signup';
    public const SOURCE_SUCCESS = 'benefactor_success';

    /**
     * Degrau 1 — registro a partir de uma conversa.
     */
    public function grantSignupBatch(Tenant $tenant): void
    {
        $this->grant(
            $tenant,
            self::SOURCE_SIGNUP,
            now()->addMonths(3),
            'Lote de brinde de registro de benfeitor concedido.'
        );
    }

    /**
     * Degrau 2 — o tutor confirmou que a conversa resolveu o problema.
     */
    public function grantSuccessBatch(EntityConversation $conversation): void
    {
        $tenant = Tenant::where('originating_conversation_id', $conversation->id)->first();

        if (!$tenant) {
            return;
        }

        $this->grant(
            $tenant,
            self::SOURCE_SUCCESS,
            now()->addYear(),
            'Lote de brinde de sucesso de benfeitor concedido.'
        );
    }

    private function grant(Tenant $tenant, string $source, $expiresAt, string $logMessage): void
    {
        try {
            $organization = $tenant->organizations()->first();

            if (!$organization) {
                Log::warning('BenefactorCreditService: tenant sem organização vinculada.', [
                    'tenant_id' => $tenant->id,
                    'source' => $source,
                ]);
                return;
            }

            $alreadyGranted = CreditBatch::where('organization_id', $organization->id)
                ->where('source', $source)
                ->exists();

            if ($alreadyGranted) {
                return;
            }

            DB::transaction(function () use ($tenant, $organization, $source, $expiresAt) {
                CreditBatch::create([
                    'creator_tenant_id' => $tenant->id,
                    'organization_id' => $organization->id,
                    'amount_total' => 1,
                    'amount_available' => 1,
                    'status' => 'active',
                    'expires_at' => $expiresAt,
                    'source' => $source,
                ]);
            });

            Log::info('BenefactorCreditService: ' . $logMessage, [
                'tenant_id' => $tenant->id,
                'organization_id' => $organization->id,
                'source' => $source,
            ]);
        } catch (\Exception $e) {
            // Brinde não pode derrubar o fluxo principal (registro ou resolução).
            Log::warning('BenefactorCreditService: falha ao conceder lote.', [
                'tenant_id' => $tenant->id,
                'source' => $source,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
