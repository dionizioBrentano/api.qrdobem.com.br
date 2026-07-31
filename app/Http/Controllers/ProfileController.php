<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TenantDocument;

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
     * Gate 1: email_verified_at + CPF + telefone
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

        if ($hasCpf && $hasPhone && $hasEmailVerified) {
            $tenant->update(['profile_status' => 'active']);
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

        // Gate 2: tudo do Gate 1 + endereço
        $entity = $purchase;
        if (empty($tenant->address_street)) {
            $entity[] = 'address';
        }

        return ['purchase' => $purchase, 'entity' => $entity];
    }

    /**
     * Validação de CPF com dígito verificador.
     */
    private function validateCpf(string $cpf): bool
    {
        $cpf = preg_replace('/\D/', '', $cpf);
        if (strlen($cpf) !== 11) {
            return false;
        }
        // Rejeita sequências iguais (000.000.000-00, 111.111.111-11, etc.)
        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $d = 0;
            for ($c = 0; $c < $t; $c++) {
                $d += $cpf[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ($cpf[$t] != $d) {
                return false;
            }
        }
        return true;
    }
}
