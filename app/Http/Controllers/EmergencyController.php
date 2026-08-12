<?php

namespace App\Http\Controllers;

use App\Mail\EmergencyDeclaredMail;
use App\Models\AuditLog;
use App\Models\Entity;
use App\Models\EntityEmergencyDeclaration;
use App\Models\Tenant;
use App\Services\QrCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class EmergencyController extends Controller
{
    public function __construct(
        private QrCodeService $qrCode
    ) {
    }

    /**
     * Declaração pública de emergência. Amplia a exposição de dados de saúde
     * daquela entidade pela janela definida em EntityEmergencyDeclaration.
     */
    public function declare(Request $request, $unique_code)
    {
        $entity = Entity::with('organization.owner')
            ->where('unique_code', $unique_code)
            ->where('is_active', true)
            ->where('status', 'active')
            ->first();

        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado.'], 404);
        }

        $request->validate([
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'location_accuracy' => 'nullable|string',
            'note' => 'nullable|string',
        ]);

        $rateLimitKey = 'emergency_declare_' . $entity->id . '_' . $request->ip();

        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            return response()->json([
                'message' => 'Alerta já registrado. Dados de socorro permanecem disponíveis nesta página.',
                'declared_at' => now(),
                'emergency_unlocked' => true,
            ], 201);
        }

        RateLimiter::hit($rateLimitKey, 900); // 15 minutos

        $declaration = EntityEmergencyDeclaration::create([
            'entity_id' => $entity->id,
            'declared_at' => now(),
            'latitude' => $request->input('latitude'),
            'longitude' => $request->input('longitude'),
            'location_accuracy' => $request->input('location_accuracy'),
            'note' => $request->input('note'),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $this->notifyOwner($entity);

        return response()->json([
            'message' => 'Emergência declarada. O responsável foi notificado.',
            'declared_at' => $declaration->declared_at,
            'emergency_unlocked' => true,
        ], 201);
    }

    /**
     * Decifragem do CPF do declarante. Restrito a superadmin e sempre auditado.
     */
    public function reveal(Request $request, $id)
    {
        $tenant = $request->tenant;

        if (!$tenant || $tenant->role !== 'superadmin') {
            return response()->json(['error' => 'Acesso negado. Apenas super administradores.'], 403);
        }

        $declaration = EntityEmergencyDeclaration::find($id);

        if (!$declaration) {
            return response()->json(['error' => 'Declaração não encontrada.'], 404);
        }

        // A auditoria é gravada ANTES de devolver o dado: se a resposta falhar
        // no meio do caminho, o acesso já está registrado.
        AuditLog::create([
            'entity_id' => $declaration->entity_id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'accessed_at' => now(),
            'location_data' => [
                'action' => 'emergency_declaration_reveal',
                'declaration_id' => $declaration->id,
                'tenant_id' => $tenant->id,
            ],
        ]);

        return response()->json([
            'declaration_id' => $declaration->id,
            'entity_id' => $declaration->entity_id,
            'declared_at' => $declaration->declared_at,
            'declarant_cpf' => $declaration->declarant_cpf_encrypted,
        ]);
    }

    private function notifyOwner(Entity $entity): void
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
            // A declaração já foi registrada; falha de SMTP não pode desfazê-la.
            Log::error('Falha ao notificar emergência declarada', [
                'entity_id' => $entity->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
