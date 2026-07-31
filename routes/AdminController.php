<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\Entity;
use App\Models\CreditBatch;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function getTenants(Request $request)
    {
        // O middleware de autenticação (FirebaseAuth) já validou o token
        $user = auth()->user();

        if ($user->role !== 'superadmin') {
            return response()->json(['error' => 'Acesso negado. Apenas super administradores.'], 403);
        }

        // Retorna todos os locatários e a soma de QRs gerados
        $tenants = Tenant::withCount('entities')->orderBy('id', 'desc')->get()->map(function($t) {
            return [
                'id' => $t->id,
                'name' => $t->name,
                'role' => $t->role,
                'quota' => $t->qr_quota,
                'used' => $t->entities_count,
                'status' => $t->is_active ? 'active' : 'blocked'
            ];
        });

        // Retorna métricas globais para a dashboard
        $metrics = [
            'total_tenants' => Tenant::where('is_active', true)->count(),
            'total_qrs' => \App\Models\Entity::count(),
            'engagement' => '+15%' // Mock de engajamento para a v1
        ];

        return response()->json([
            'tenants' => $tenants,
            'metrics' => $metrics
        ]);
    }
}
