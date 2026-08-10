<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\Entity;
use App\Models\CreditBatch;
use App\Models\Organization;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    /**
     * Verifica se o tenant autenticado é superadmin.
     */
    private function authorizeSuperAdmin(Request $request): ?Tenant
    {
        $tenant = $request->tenant;

        if (!$tenant || $tenant->role !== 'superadmin') {
            return null;
        }

        return $tenant;
    }

    public function getTenants(Request $request)
    {
        if (!$this->authorizeSuperAdmin($request)) {
            return response()->json(['error' => 'Acesso negado. Apenas super administradores.'], 403);
        }

        $tenants = Tenant::with(['organizations.batches', 'receivedCreditBatches'])
            ->orderBy('id', 'desc')->get()->map(function ($t) {
            
            // Lotes diretos do Tenant
            $directBatches = $t->receivedCreditBatches;
            $directActive = $directBatches->filter(function($b) {
                return $b->status === 'active' && (!$b->expires_at || $b->expires_at->isFuture());
            });
            $directCredits = $directActive->sum('amount_available');

            // Lotes das Organizações
            $orgBatches = collect();
            $organizations = $t->organizations->map(function ($org) use (&$orgBatches) {
                $activeBatches = $org->batches->filter(function($b) {
                    return $b->status === 'active' && (!$b->expires_at || $b->expires_at->isFuture());
                });
                
                $orgBatches = $orgBatches->merge($org->batches);

                return [
                    'id' => $org->id,
                    'name' => $org->name,
                    'document' => $org->document,
                    'credits_available' => $activeBatches->sum('amount_available'),
                ];
            });

            // Total disponível
            $orgCredits = $organizations->sum('credits_available');

            // Conta entidades através das organizações do tenant
            $entityCount = Entity::whereIn('organization_id', $t->organizations->pluck('id'))->count();
            
            return [
                'id' => $t->id,
                'name' => $t->name,
                'email' => $t->email,
                'role' => $t->role,
                'profile_status' => $t->profile_status,
                'quota' => $t->qr_quota,
                'used' => $entityCount,
                'status' => $t->is_active ? 'active' : 'blocked',
                'credits_available' => $directCredits + $orgCredits,
                'direct_credits' => $directCredits,
                'organizations' => $organizations,
                'credit_batches' => $directBatches->merge($orgBatches)->sortByDesc('created_at')->values()->all(),
            ];
        });

        $pricing = \App\Models\CreditPricing::first();

        $metrics = [
            'total_tenants' => Tenant::where('is_active', true)->count(),
            'total_qrs' => Entity::count(),
            'engagement' => '+15%',
            'pricing' => $pricing ? [
                'unit_price' => $pricing->unit_price,
            ] : null,
        ];

        return response()->json([
            'tenants' => $tenants,
            'metrics' => $metrics,
        ]);
    }

    public function createBatch(Request $request)
    {
        if (!$this->authorizeSuperAdmin($request)) {
            return response()->json(['error' => 'Acesso negado. Apenas super administradores.'], 403);
        }

        $request->validate([
            'tenant_id' => 'required|exists:tenants,id',
            'organization_id' => 'nullable|exists:organizations,id',
            'amount' => 'required|integer|min:1',
            'expires_at' => 'nullable|date|after:today',
            'note' => 'nullable|string',
        ]);

        $superadmin = $request->tenant;
        $targetTenant = Tenant::with('organizations')->find($request->tenant_id);

        $orgId = $request->organization_id;
        $recipientId = null;

        $orgs = $targetTenant->organizations;
        
        if ($orgs->count() === 0) {
            // Grupo/Pessoa sem CNPJ, os créditos vão pro CPF
            $recipientId = $targetTenant->id;
        } elseif ($orgs->count() === 1) {
            // Se tiver 1 CNPJ, atribui automático a ele, caso não tenha mandado
            $orgId = $orgId ?: $orgs->first()->id;
        } else {
            // Mais de 1 CNPJ
            if (!$orgId) {
                return response()->json(['error' => 'MULTIPLE_ORGANIZATIONS'], 422);
            }
        }

        $batch = CreditBatch::create([
            'creator_tenant_id' => $superadmin->id,
            'recipient_tenant_id' => $recipientId,
            'organization_id' => $orgId,
            'amount_total' => $request->amount,
            'amount_available' => $request->amount,
            'status' => 'active',
            'expires_at' => $request->expires_at,
            'source' => $request->note ?: 'manual_admin',
        ]);

        return response()->json([
            'message' => 'Lote de créditos criado com sucesso.',
            'batch' => $batch,
        ], 201);
    }

    public function toggleBatchStatus(Request $request, $id)
    {
        if (!$this->authorizeSuperAdmin($request)) {
            return response()->json(['error' => 'Acesso negado. Apenas super administradores.'], 403);
        }

        $batch = CreditBatch::find($id);

        if (!$batch) {
            return response()->json(['error' => 'Lote não encontrado.'], 404);
        }

        $newStatus = $batch->status === 'active' ? 'suspended' : 'active';
        $batch->update(['status' => $newStatus]);

        return response()->json([
            'message' => "Status do lote alterado para {$newStatus}.",
            'batch' => $batch,
        ]);
    }

    public function updatePricing(Request $request)
    {
        if (!$this->authorizeSuperAdmin($request)) {
            return response()->json(['error' => 'Acesso negado. Apenas super administradores.'], 403);
        }

        $request->validate([
            'unit_price' => 'required|numeric|min:0.01',
            'min_quantity' => 'required|integer|min:1',
            'max_quantity' => 'required|integer|min:1|gte:min_quantity',
        ]);

        $pricing = \App\Models\CreditPricing::first();

        if ($pricing) {
            $pricing->update($request->only(['unit_price', 'min_quantity', 'max_quantity']));
        } else {
            $pricing = \App\Models\CreditPricing::create($request->only(['unit_price', 'min_quantity', 'max_quantity']));
        }

        return response()->json([
            'message' => 'Configurações de preço atualizadas com sucesso.',
            'pricing' => $pricing,
        ]);
    }
}
