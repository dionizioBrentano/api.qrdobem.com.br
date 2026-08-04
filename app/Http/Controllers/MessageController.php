<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\EntityConversation;
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

    /**
     * Inbox do tutor, agrupado por conversa.
     *
     * Mensagens avulsas criadas antes do chat mediado não têm conversation_id;
     * cada uma vira uma thread de mensagem única para não sumir da caixa.
     */
    public function index(Request $request)
    {
        $tenant = $request->tenant;

        // Pega os IDs de todas as organizações que o usuário tem acesso
        $orgIds = $tenant->organizations()->pluck('organizations.id');

        // Pega as entidades dessas organizações
        $entityIds = Entity::whereIn('organization_id', $orgIds)->pluck('id');

        $conversations = EntityConversation::with(['entity', 'messages'])
            ->whereIn('entity_id', $entityIds)
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(fn ($c) => $this->presentConversation($c));

        $legacy = EntityMessage::with('entity')
            ->whereIn('entity_id', $entityIds)
            ->whereNull('conversation_id')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($m) => $this->presentLegacyMessage($m));

        return response()->json([
            'conversations' => $conversations->concat($legacy)->values(),
        ]);
    }

    private function presentConversation(EntityConversation $conversation): array
    {
        $messages = $conversation->messages->sortBy('id')->values();
        $last = $messages->last();

        return [
            'id' => $conversation->id,
            'is_legacy' => false,
            'benefactor_nickname' => $conversation->benefactor_nickname,
            'resolved_at' => $conversation->resolved_at,
            'entity' => $this->presentEntity($conversation->entity),
            'unread_count' => $messages
                ->where('sender_type', 'benefactor')
                ->whereNull('read_at')
                ->count(),
            'last_message_at' => $last?->created_at,
            'messages' => $messages->map(fn ($m) => [
                'id' => $m->id,
                'sender_type' => $m->sender_type,
                'sender_name' => $m->sender_name,
                'message' => $m->message,
                'latitude' => $m->latitude,
                'longitude' => $m->longitude,
                'read_at' => $m->read_at,
                'created_at' => $m->created_at,
            ]),
        ];
    }

    private function presentLegacyMessage(EntityMessage $message): array
    {
        return [
            'id' => null,
            'is_legacy' => true,
            'benefactor_nickname' => $message->sender_name,
            'resolved_at' => null,
            'entity' => $this->presentEntity($message->entity),
            'unread_count' => $message->read_at ? 0 : 1,
            'last_message_at' => $message->created_at,
            'messages' => [[
                'id' => $message->id,
                'sender_type' => $message->sender_type ?? 'benefactor',
                'sender_name' => $message->sender_name,
                'message' => $message->message,
                'latitude' => $message->latitude,
                'longitude' => $message->longitude,
                'read_at' => $message->read_at,
                'created_at' => $message->created_at,
            ]],
        ];
    }

    private function presentEntity(?Entity $entity): ?array
    {
        if (!$entity) {
            return null;
        }

        return [
            'unique_code' => $entity->unique_code,
            'type' => $entity->type,
            'name' => $entity->encrypted_name,
        ];
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
