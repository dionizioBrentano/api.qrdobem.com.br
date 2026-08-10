<?php

namespace App\Http\Controllers;

use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\Tenant;
use Illuminate\Http\Request;

class SpaceMemberController extends Controller
{
    /**
     * Valida se o tenant autenticado pode gerenciar membros neste espaço.
     */
    private function authorizeManageMembers(Request $request, Space $space)
    {
        $member = $space->members()->where('tenant_id', $request->tenant->id)->first();
        if (!$member || !$member->can('member.view')) {
            abort(403, 'Acesso negado.');
        }
        return $member;
    }

    public function index(Request $request, Space $space)
    {
        $this->authorizeManageMembers($request, $space);

        $members = $space->members()->with(['tenant:id,name,email,role,is_active'])->get();

        return response()->json([
            'members' => $members
        ]);
    }

    public function invite(Request $request, Space $space)
    {
        $me = $this->authorizeManageMembers($request, $space);
        if (!$me->can('member.invite')) {
            return response()->json(['error' => 'Sem permissão para convidar membros.'], 403);
        }

        $request->validate([
            'email' => 'required|email',
            'role' => 'required|in:admin,manager,member',
        ]);

        $tenant = Tenant::where('email', $request->email)->first();
        if (!$tenant) {
            return response()->json(['error' => 'Usuário não encontrado com este e-mail. Ele precisa se cadastrar primeiro.'], 404);
        }

        if ($space->members()->where('tenant_id', $tenant->id)->exists()) {
            return response()->json(['error' => 'Usuário já faz parte deste espaço.'], 422);
        }

        $member = $space->members()->create([
            'tenant_id' => $tenant->id,
            'role' => $request->role,
            'invited_by_tenant_id' => $me->tenant_id,
            // Na Fase 1 o aceite pode ser manual ou auto dependendo da regra, vamos assumir auto-aceite pra simplificar
            'accepted_at' => now(), 
        ]);

        return response()->json([
            'message' => 'Membro convidado com sucesso.',
            'member' => $member->load('tenant:id,name,email')
        ], 201);
    }

    public function updateRole(Request $request, Space $space, SpaceMember $member)
    {
        $me = $this->authorizeManageMembers($request, $space);
        // Apenas owner ou admin (com permissão) pode mudar papéis
        if ($me->role !== 'owner' && !$me->can('member.permission')) {
            return response()->json(['error' => 'Sem permissão para alterar cargos.'], 403);
        }

        // Não pode rebaixar o owner
        if ($member->role === 'owner') {
            return response()->json(['error' => 'Não é possível alterar o cargo do dono do espaço.'], 403);
        }

        $request->validate([
            'role' => 'required|in:admin,manager,member',
        ]);

        $member->update(['role' => $request->role]);

        return response()->json([
            'message' => 'Cargo atualizado com sucesso.',
            'member' => $member
        ]);
    }

    public function remove(Request $request, Space $space, SpaceMember $member)
    {
        $me = $this->authorizeManageMembers($request, $space);
        if (!$me->can('member.remove')) {
            return response()->json(['error' => 'Sem permissão para remover membros.'], 403);
        }

        if ($member->role === 'owner') {
            return response()->json(['error' => 'Não é possível remover o dono do espaço.'], 403);
        }

        $member->delete();

        return response()->json(['message' => 'Membro removido com sucesso.']);
    }
}
