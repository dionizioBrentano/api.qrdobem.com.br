<?php

namespace App\Services;

use App\Models\Entity;
use App\Mail\NewConversationMessageMail;
use App\Mail\EmergencyDeclaredMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OwnerMailNotifier
{
    public function __construct(
        private QrCodeService $qrCode
    ) {
    }

    public function notifyNewConversationMessage(Entity $entity): void
    {
        $owner = $entity->organization?->owner;

        if (!$owner || empty($owner->email)) {
            return;
        }

        $link = config('qrdobem.frontend_url') . '/messages';

        try {
            Mail::to($owner->email)->send(
                new NewConversationMessageMail($entity->encrypted_name, $link)
            );
        } catch (\Exception $e) {
            Log::error('Falha ao notificar tutor de nova mensagem', [
                'entity_id' => $entity->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function notifyEmergencyDeclared(Entity $entity): void
    {
        $owner = $entity->organization?->owner;

        if (!$owner || empty($owner->email)) {
            return;
        }

        try {
            Mail::to($owner->email)->send(
                new EmergencyDeclaredMail($entity->encrypted_name, $this->qrCode->urlFor($entity->unique_code))
            );
        } catch (\Exception $e) {
            Log::error('Falha ao notificar emergência declarada', [
                'entity_id' => $entity->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
