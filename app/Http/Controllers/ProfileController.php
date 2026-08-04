<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TenantDocument;
use App\Services\CpfValidator;

class ProfileController extends Controller
{
    /**
     * Retorna dados atuais do perfil + o que falta para cada gate.
     */
    public function show(Request $request)
    {
        $tenant = $request->tenant;
        $missing = $this->getMissingFields($tenant);

        return response()->json([
            'tenant' => $tenant,
            'documents' => $tenant->documents,
            'missing_for_purchase' => $missing['purchase'],
            'missing_for_entity' => $missing['entity'],
            'can_purchase' => empty($missing['purchase']),
            'can_create_entity' => empty($missing['entity']),
        ]);
    }

    /**
     * Atualiza dados do perfil (nome, telefone, endereço).
     */
    public function update(Request $request)
    {
        $tenant = $request->tenant;

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'nickname' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'address_street' => 'sometimes|string|max:255',
            'address_number' => 'sometimes|string|max:20',
            'address_complement' => 'sometimes|string|max:255',
            'address_neighborhood' => 'sometimes|string|max:255',
            'address_city' => 'sometimes|string|max:255',
            'address_state' => 'sometimes|string|size:2',
            'address_zipcode' => 'sometimes|string|max:9',
        ]);

        $tenant->update($validated);
        $tenant = $tenant->fresh();

        // Verifica se agora pode ser ativado
        $this->checkAndActivate($tenant);

        return response()->json([
            'message' => 'Perfil atualizado.',
            'tenant' => $tenant->fresh(),
        ]);
    }

    /**
     * Adiciona documento de identificação (CPF, RG, CNH, etc.).
     * Documento é criptografado via cast no model.
     */
    public function addDocument(Request $request)
    {
        $tenant = $request->tenant;

        $validated = $request->validate([
            'document_type' => 'required|string|max:50',
            'document_number' => 'required|string|max:50',
            'document_country' => 'sometimes|string|max:5',
            'is_primary' => 'sometimes|boolean',
        ]);

        // Validação específica de CPF (dígito verificador)
        if ($validated['document_type'] === 'cpf') {
            $cpfClean = preg_replace('/\D/', '', $validated['document_number']);
            if (!$this->validateCpf($cpfClean)) {
                return response()->json(['error' => 'CPF inválido.'], 422);
            }
            // Atualiza também o campo cpf na tabela tenants (atalho para Gate 1)
            $tenant->update(['cpf' => $cpfClean]);
        }

        // Verifica duplicata (mesmo tipo = atualiza)
        $existing = TenantDocument::where('tenant_id', $tenant->id)
            ->where('document_type', $validated['document_type'])
            ->first();

        if ($existing) {
            $existing->update($validated);
            $doc = $existing;
        } else {
            $doc = TenantDocument::create(array_merge($validated, [
                'tenant_id' => $tenant->id,
            ]));
        }

        $tenant = $tenant->fresh();
        $this->checkAndActivate($tenant);

        return response()->json([
            'message' => 'Documento cadastrado.',
            'document' => $doc,
            'tenant' => $tenant->fresh(),
        ]);
    }

    /**
     * Verifica se o tenant cumpre todos os requisitos para 'active' (Gate 1).
     * Gate 1: email_verified_at + CPF + telefone + apelido
     */
    private function checkAndActivate($tenant)
    {
        if ($tenant->profile_status === 'active') {
            return;
        }

        $hasCpf = !empty($tenant->cpf)
            || TenantDocument::where('tenant_id', $tenant->id)
                ->where('document_type', 'cpf')
                ->exists();
        $hasPhone = !empty($tenant->phone);
        $hasEmailVerified = !empty($tenant->email_verified_at);
        $hasNickname = !empty($tenant->nickname);

        if ($hasCpf && $hasPhone && $hasEmailVerified && $hasNickname) {
            $tenant->update(['profile_status' => 'active']);
            
            // Concede créditos de onboarding ao atingir o status active
            app(\App\Services\OnboardingCreditService::class)->grantOnboardingBatch($tenant);
        }
    }

    /**
     * Retorna campos faltantes por operação (Gate 1 e Gate 2).
     */
    private function getMissingFields($tenant)
    {
        $purchase = [];

        if (empty($tenant->email_verified_at)) {
            $purchase[] = 'email_verified';
        }
        if (empty($tenant->cpf) && !TenantDocument::where('tenant_id', $tenant->id)->where('document_type', 'cpf')->exists()) {
            $purchase[] = 'cpf';
        }
        if (empty($tenant->phone)) {
            $purchase[] = 'phone';
        }
        if (empty($tenant->nickname)) {
            $purchase[] = 'nickname';
        }

        // Gate 2: tudo do Gate 1 + endereço
        $entity = $purchase;
        if (empty($tenant->address_street)) {
            $entity[] = 'address';
        }

        return ['purchase' => $purchase, 'entity' => $entity];
    }

    /**
     * Validação de CPF com dígito verificador.
     * O algoritmo vive em CpfValidator, compartilhado com a Declaração de Emergência.
     */
    private function validateCpf(string $cpf): bool
    {
        return app(CpfValidator::class)->isValid($cpf);
    }
}
