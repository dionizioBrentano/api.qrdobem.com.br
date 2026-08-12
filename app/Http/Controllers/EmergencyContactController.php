<?php

namespace App\Http\Controllers;

use App\Models\EmergencyContact;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EmergencyContactController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = $request->tenant->id;
        
        $query = EmergencyContact::where('owner_tenant_id', $tenantId);
        
        if ($request->has('space_id')) {
            $query->where('space_id', $request->space_id);
        }
        if ($request->has('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }
        
        $contacts = $query->get()->map(function ($contact) {
            return [
                'id' => $contact->id,
                'name' => $contact->name,
                // Mascara telefone se for listagem geral para maior privacidade
                'phone' => $contact->phone ? '***' . substr($contact->phone, -4) : null,
                'email' => $contact->email,
                'status' => $contact->status,
                'space_id' => $contact->space_id,
                'entity_id' => $contact->entity_id,
                'invite_token' => $contact->invite_token, // Permitimos que o owner veja o token p/ link
                'term_accepted_at' => $contact->term_accepted_at,
            ];
        });

        return response()->json(['contacts' => $contacts]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'space_id' => 'nullable|exists:spaces,id',
            'entity_id' => 'nullable|exists:entities,id',
        ]);

        if (empty($validated['space_id']) && empty($validated['entity_id'])) {
            return response()->json(['error' => 'É necessário vincular o contato a um espaço ou entidade.'], 422);
        }

        $validated['owner_tenant_id'] = $request->tenant->id;
        $validated['invite_token'] = Str::random(32);
        $validated['status'] = 'pending';

        $contact = EmergencyContact::create($validated);

        return response()->json([
            'message' => 'Contato de emergência criado com sucesso.',
            'contact' => $contact
        ], 201);
    }

    public function destroy(Request $request, $id)
    {
        $contact = EmergencyContact::where('owner_tenant_id', $request->tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        // Pode ser soft delete ou update status
        $contact->update(['status' => 'revoked']);
        $contact->delete();

        return response()->json(['message' => 'Contato removido com sucesso.']);
    }

    public function resendInvite(Request $request, $id)
    {
        $contact = EmergencyContact::where('owner_tenant_id', $request->tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        if ($contact->status !== 'pending') {
            return response()->json(['error' => 'Só é possível reenviar convite para contatos pendentes.'], 422);
        }

        $contact->update([
            'invite_token' => Str::random(32)
        ]);

        return response()->json([
            'message' => 'Convite gerado novamente.',
            'invite_token' => $contact->invite_token
        ]);
    }
}
