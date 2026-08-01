<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\CreditBatch;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class OnboardingCreditService
{
    /**
     * Concede o lote de onboarding para a organização Matriz do tenant,
     * caso o tenant tenha acabado de se tornar 'active' e ainda não tenha recebido.
     */
    public function grantOnboardingBatch(Tenant $tenant): void
    {
        try {
            // Verifica se o perfil realmente está ativo
            if ($tenant->profile_status !== 'active') {
                return;
            }

            // Pega a primeira organização do tenant (considerada a Matriz)
            $organization = $tenant->organizations()->first();

            if (!$organization) {
                Log::warning('OnboardingCreditService: Tenant ativado sem organização vinculada.', ['tenant_id' => $tenant->id]);
                return;
            }

            // Garante idempotência: se já existe um batch com source 'onboarding' nesta organização
            $existingBatch = CreditBatch::where('organization_id', $organization->id)
                ->where('source', 'onboarding')
                ->exists();

            if ($existingBatch) {
                return; // Já recebeu o onboarding
            }

            $amount = (int) config('qrdobem.onboarding_credits', 3);

            if ($amount <= 0) {
                return;
            }

            DB::transaction(function () use ($tenant, $organization, $amount) {
                CreditBatch::create([
                    'creator_tenant_id' => $tenant->id,
                    'organization_id' => $organization->id,
                    'amount_total' => $amount,
                    'amount_available' => $amount,
                    'status' => 'active',
                    'expires_at' => null,
                    'source' => 'onboarding',
                ]);
            });

            Log::info('OnboardingCreditService: Lote de onboarding concedido.', [
                'tenant_id' => $tenant->id,
                'organization_id' => $organization->id,
                'amount' => $amount,
            ]);

        } catch (\Exception $e) {
            // Falha ao criar batch não deve impedir a ativação do perfil (logar erro e seguir)
            Log::warning('OnboardingCreditService: Falha ao conceder lote de onboarding.', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
