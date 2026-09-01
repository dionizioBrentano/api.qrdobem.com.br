<?php

namespace App\Http\Controllers;

use App\Mail\NewConversationMessageMail;
use App\Models\Entity;
use App\Models\EntityConversation;
use App\Models\EntityMessage;
use App\Services\BenefactorCreditService;
use App\Services\PiiDetector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ConversationController extends Controller
{
    public function __construct(private PiiDetector $pii, private \App\Services\OwnerMailNotifier $notifier)
    {
    }

    /**
     * Cria uma conversa a partir do QR público (benfeitor -> tutor).
     */
    public function store(Request $request, $unique_code)
    {
        $entity = $this->findPublicEntity($unique_code);

        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado.'], 404);
        }

        $request->validate([
            'message' => 'required|string',
            'benefactor_nickname' => 'nullable|string|max:255',
            'recovery_code' => 'nullable|string|size:4',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'confirm_risk' => 'nullable|boolean',
        ]);

        if ($contactError = $this->rejectIfContact($request)) {
            return $contactError;
        }

        $recoveryCode = $request->input('recovery_code');

        // Unicidade por entidade: o hash é unidirecional, então a colisão só pode
        // ser detectada comparando o código informado contra os hashes já
        // existentes daquela entidade. O volume por entidade é baixo.
        if ($recoveryCode && $this->recoveryCodeTaken($entity, $recoveryCode)) {
            return response()->json([
                'error' => 'Este código de recuperação já está em uso para este registro. Escolha outro.',
                'code' => 'RECOVERY_CODE_TAKEN',
            ], 422);
        }

        $conversation = EntityConversation::create([
            'entity_id' => $entity->id,
            'recovery_code_hash' => $recoveryCode ? Hash::make($recoveryCode) : null,
            'benefactor_nickname' => $request->input('benefactor_nickname'),
        ]);

        EntityMessage::create([
            'entity_id' => $entity->id,
            'conversation_id' => $conversation->id,
            'sender_type' => 'benefactor',
            'sender_name' => $request->input('benefactor_nickname'),
            'message' => $request->input('message'),
            'latitude' => $request->input('latitude'),
            'longitude' => $request->input('longitude'),
        ]);

        if ($recoveryCode) {
            EntityMessage::create([
                'entity_id' => $entity->id,
                'conversation_id' => $conversation->id,
                'sender_type' => 'system',
                'message' => "Guarde este código: se precisar continuar essa conversa de outro aparelho, escaneie o QR novamente e informe o código {$recoveryCode}.",
            ]);
        }

        $this->notifier->notifyNewConversationMessage($entity);

        return response()->json($this->present($conversation->fresh()), 201);
    }

    /**
     * Mensagem adicional do benfeitor dentro de uma conversa existente.
     */
    public function addMessage(Request $request, $unique_code, $conversation_id)
    {
        $entity = $this->findPublicEntity($unique_code);

        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado.'], 404);
        }

        $conversation = $entity->conversations()->where('id', $conversation_id)->first();

        if (!$conversation) {
            return response()->json(['error' => 'Conversa não encontrada.'], 404);
        }

        $request->validate([
            'message' => 'required|string',
            'confirm_risk' => 'nullable|boolean',
        ]);

        if ($contactError = $this->rejectIfContact($request)) {
            return $contactError;
        }

        EntityMessage::create([
            'entity_id' => $entity->id,
            'conversation_id' => $conversation->id,
            'sender_type' => 'benefactor',
            'sender_name' => $conversation->benefactor_nickname,
            'message' => $request->input('message'),
        ]);

        $this->notifier->notifyNewConversationMessage($entity);

        return response()->json($this->present($conversation->fresh()), 201);
    }

    /**
     * Recupera uma conversa em outro aparelho pelo código de 4 caracteres.
     */
    public function recover(Request $request, $unique_code)
    {
        $entity = $this->findPublicEntity($unique_code);

        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado.'], 404);
        }

        $request->validate([
            'recovery_code' => 'required|string',
        ]);

        $conversation = $this->findByRecoveryCode($entity, $request->input('recovery_code'));

        // 404 genérico de propósito: não revela se o código chegou perto de existir.
        if (!$conversation) {
            return response()->json(['error' => 'Conversa não encontrada.'], 404);
        }

        return response()->json($this->present($conversation));
    }

    /**
     * Histórico da conversa (usado pelo polling da página pública).
     */
    public function show(Request $request, $unique_code, $conversation_id)
    {
        $entity = $this->findPublicEntity($unique_code);

        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado.'], 404);
        }

        $conversation = $entity->conversations()->where('id', $conversation_id)->first();

        if (!$conversation) {
            return response()->json(['error' => 'Conversa não encontrada.'], 404);
        }

        return response()->json($this->present($conversation));
    }

    /**
     * Resposta do tutor dentro da thread (rota autenticada).
     */
    public function tenantReply(Request $request, $conversation_id)
    {
        $conversation = $this->findOwnedConversation($request, $conversation_id);

        if (!$conversation) {
            return response()->json(['error' => 'Conversa não encontrada.'], 404);
        }

        $request->validate([
            'message' => 'required|string',
            'confirm_risk' => 'nullable|boolean',
        ]);

        if ($contactError = $this->rejectIfContact($request)) {
            return $contactError;
        }

        EntityMessage::create([
            'entity_id' => $conversation->entity_id,
            'conversation_id' => $conversation->id,
            'sender_type' => 'tenant',
            'message' => $request->input('message'),
        ]);

        return response()->json($this->present($conversation->fresh()), 201);
    }

    /**
     * Tutor marca a conversa como resolvida. Não altera o status da Entity:
     * o QR continua ativo normalmente (decisão de produto).
     */
    public function resolve(Request $request, $conversation_id)
    {
        $conversation = $this->findOwnedConversation($request, $conversation_id);

        if (!$conversation) {
            return response()->json(['error' => 'Conversa não encontrada.'], 404);
        }

        if (!$conversation->resolved_at) {
            $conversation->update(['resolved_at' => now()]);

            // Funil Benfeitor -> Tutor (Degrau 2): sucesso confirmado pelo tutor.
            // O serviço é idempotente e não faz nada se ninguém se registrou por aqui.
            app(BenefactorCreditService::class)->grantSuccessBatch($conversation);
        }

        return response()->json($this->present($conversation->fresh()));
    }

    // --- Helpers ---

    private function findPublicEntity($unique_code)
    {
        return Entity::where('unique_code', $unique_code)
            ->where('is_active', true)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Conversa de uma entidade que pertence a alguma organização do tenant
     * autenticado. Mesmo padrão de verificação de posse de EntityController::qrCode.
     */
    private function findOwnedConversation(Request $request, $conversation_id)
    {
        $tenant = $request->tenant;
        $orgIds = $tenant->organizations()->pluck('organizations.id');

        $entityIds = Entity::whereIn('organization_id', $orgIds)->pluck('id');

        return EntityConversation::where('id', $conversation_id)
            ->whereIn('entity_id', $entityIds)
            ->first();
    }

    /**
     * Devolve a resposta 422 de contato detectado, ou null se pode seguir.
     */
    private function rejectIfContact(Request $request)
    {
        if ($request->boolean('confirm_risk')) {
            return null;
        }

        if (!$this->pii->containsContact($request->input('message'))) {
            return null;
        }

        return response()->json([
            'error' => PiiDetector::CONTACT_MESSAGE,
            'code' => 'CONTACT_DETECTED',
        ], 422);
    }

    private function recoveryCodeTaken(Entity $entity, string $code): bool
    {
        return $this->findByRecoveryCode($entity, $code) !== null;
    }

    private function findByRecoveryCode(Entity $entity, string $code): ?EntityConversation
    {
        $candidates = $entity->conversations()->whereNotNull('recovery_code_hash')->get();

        foreach ($candidates as $conversation) {
            if (Hash::check($code, $conversation->recovery_code_hash)) {
                return $conversation;
            }
        }

        return null;
    }

    private function present(EntityConversation $conversation): array
    {
        $messages = $conversation->messages()->orderBy('id')->get();

        return [
            'id' => $conversation->id,
            'benefactor_nickname' => $conversation->benefactor_nickname,
            'resolved_at' => $conversation->resolved_at,
            'created_at' => $conversation->created_at,
            'messages' => $messages->map(function ($m) {
                return [
                    'id' => $m->id,
                    'sender_type' => $m->sender_type,
                    'sender_name' => $m->sender_name,
                    'message' => $m->message,
                    'created_at' => $m->created_at,
                ];
            }),
        ];
    }
}
