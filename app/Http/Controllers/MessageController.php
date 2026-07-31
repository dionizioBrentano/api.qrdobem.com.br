<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\EntityMessage;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    // Rota pública para enviar a mensagem (Benemérito -> Proprietário)
    public function storePublic(Request $request, $unique_code)
    {
        $entity = Entity::where('unique_code', $unique_code)->where('is_active', true)->first();

        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado.'], 404);
        }

        $request->validate([
            'sender_name' => 'required|string|max:255',
            'message' => 'required|string',
            'sender_contact' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $message = EntityMessage::create([
            'entity_id' => $entity->id,
            'sender_name' => $request->sender_name,
            'sender_contact' => $request->sender_contact,
            'message' => $request->message,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
        ]);

        return response()->json(['message' => 'Mensagem enviada com sucesso ao proprietário.'], 201);
    }

    // Rota privada para o proprietário ler as mensagens
    public function index(Request $request)
    {
        $tenant = $request->tenant;
        
        // Pega os IDs de todas as organizações que o usuário tem acesso
        $orgIds = $tenant->organizations()->pluck('organizations.id');

        // Pega as entidades dessas organizações
        $entityIds = Entity::whereIn('organization_id', $orgIds)->pluck('id');

        // Retorna as mensagens
        $messages = EntityMessage::with('entity')
            ->whereIn('entity_id', $entityIds)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($messages);
    }

    public function markAsRead(Request $request, $id)
    {
        $tenant = $request->tenant;

        // IDs das organizações do tenant
        $orgIds = $tenant->organizations()->pluck('organizations.id');
        // IDs das entidades dessas organizações
        $entityIds = Entity::whereIn('organization_id', $orgIds)->pluck('id');

        // Só marca se a mensagem pertence a uma entidade do tenant
        $message = EntityMessage::where('id', $id)
            ->whereIn('entity_id', $entityIds)
            ->first();

        if (!$message) {
            return response()->json(['error' => 'Mensagem não encontrada.'], 404);
        }

        if (!$message->read_at) {
            $message->update(['read_at' => now()]);
        }

        return response()->json(['success' => true]);
    }
}
