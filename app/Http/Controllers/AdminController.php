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

        $tenants = Tenant::with('organizations')->orderBy('id', 'desc')->get()->map(function ($t) {
            // Conta entidades através das organizações do tenant
            $entityCount = Entity::whereIn('organization_id', $t->organizations->pluck('id'))->count();
            return [
                'id' => $t->id,
                'name' => $t->name,
                'role' => $t->role,
                'profile_status' => $t->profile_status,
                'quota' => $t->qr_quota,
                'used' => $entityCount,
                'status' => $t->is_active ? 'active' : 'blocked',
            ];
        });

        $metrics = [
            'total_tenants' => Tenant::where('is_active', true)->count(),
            'total_qrs' => Entity::count(),
            'engagement' => '+15%',
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
            'organization_id' => 'required|exists:organizations,id',
            'amount' => 'required|integer|min:1',
            'expires_at' => 'nullable|date|after:today',
        ]);

        $tenant = $request->tenant;

        $batch = CreditBatch::create([
            'organization_id' => $request->organization_id,
            'creator_tenant_id' => $tenant->id,
            'amount_total' => $request->amount,
            'amount_available' => $request->amount,
            'status' => 'active',
            'expires_at' => $request->expires_at,
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
