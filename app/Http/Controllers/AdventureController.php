<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\CreditBatch;
use App\Models\Space;
use App\Policies\SpacePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdventureController extends Controller
{
    public function listReferencePoints(Request $request, $unique_code)
    {
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);

        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        if ($entity->type !== 'person') {
            return response()->json(['error' => 'Trilha Aventura suportada apenas para pessoas.'], 400);
        }

        return response()->json($entity->referencePoints);
    }

    public function storeReferencePoint(Request $request, $unique_code)
    {
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);

        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        if ($entity->type !== 'person') {
            return response()->json(['error' => 'Trilha Aventura suportada apenas para pessoas.'], 400);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'radius_meters' => 'nullable|integer|min:' . (int) config('adventure.min_radius_meters'),
            'days_of_week' => 'nullable|array',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
        ]);

        if (!isset($validated['radius_meters'])) {
            $validated['radius_meters'] = (int) config('adventure.default_radius_meters');
        }

        $point = $entity->referencePoints()->create($validated);

        return response()->json($point, 201);
    }

    public function destroyReferencePoint(Request $request, $unique_code, $point_id)
    {
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);

        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        $point = $entity->referencePoints()->where('id', $point_id)->firstOrFail();
        $point->delete();

        return response()->json(['message' => 'Ponto de referência removido com sucesso.']);
    }

    public function setSilentPassword(Request $request, $unique_code)
    {
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);

        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        if ($entity->type !== 'person') {
            return response()->json(['error' => 'Trilha Aventura suportada apenas para pessoas.'], 400);
        }

        $request->validate([
            'password' => 'required|string|min:6|max:100',
        ]);

        $entity->silent_password_hash = Hash::make($request->password);
        $entity->save();

        return response()->json(['message' => 'Senha silenciosa definida com sucesso.']);
    }

    public function createChallenge(Request $request, $unique_code)
    {
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);

        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        if ($entity->type !== 'person') {
            return response()->json(['error' => 'Trilha Aventura suportada apenas para pessoas.'], 400);
        }

        $validated = $request->validate([
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'detected_at' => 'nullable|date',
        ]);

        $challenge = \App\Models\AdventureEvent::create([
            'entity_id' => $entity->id,
            'type' => 'pending_challenge',
            'status' => 'pending',
            'metadata' => $validated,
        ]);

        return response()->json([
            'challenge_id' => $challenge->id,
            'message' => 'Você parece estar fora da sua rotina. Confirme com sua senha.',
        ], 201);
    }

    public function silentTrigger(Request $request, $unique_code)
    {
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);

        if (!$entity) {
            // Se falhar autorização, em teoria o padrão da API já é 404.
            // Para manter o silent total mesmo sem auth, precisaria ver se é a ideia,
            // mas o prompt diz "Só o dono autenticado da Entity (via canAccessEntity) pode chamar esses endpoints".
            // Então retornar 404 aqui é esperado e alinhado com o resolveEntity padrão.
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        if ($entity->type !== 'person') {
            return response()->json(['error' => 'Trilha Aventura suportada apenas para pessoas.'], 400);
        }

        $request->validate([
            'challenge_id' => 'required|integer',
            'password' => 'required|string',
        ]);

        $challenge = \App\Models\AdventureEvent::where('id', $request->challenge_id)
            ->where('entity_id', $entity->id)
            ->where('status', 'pending')
            ->first();

        // Sempre a mesma resposta genérica, independente de falhas a partir daqui
        $genericResponse = response()->json([
            'message' => 'Verificação registrada.'
        ], 200);

        if (!$challenge) {
            return $genericResponse;
        }

        if (!$entity->silent_password_hash || !Hash::check($request->password, $entity->silent_password_hash)) {
            return $genericResponse;
        }

        // Senha correta: atualiza status e dispara pânico
        $challenge->update([
            'type' => 'silent_triggered',
            'status' => 'resolved'
        ]);

        // Dispara pânico silencioso
        $space = $entity->space_id ? \App\Models\Space::find($entity->space_id) : null;
        
        $panicController = app(\App\Http\Controllers\PanicController::class);
        $panicEvent = $panicController->createEvent(
            $space,
            [
                'entity_id' => $entity->id,
                'latitude' => $challenge->metadata['latitude'] ?? null,
                'longitude' => $challenge->metadata['longitude'] ?? null,
                'note' => 'Alerta Silencioso (Trilha Aventura)',
            ],
            \App\Models\PanicEvent::SOURCE_APP,
            $request->tenant
        );

        if ($space) {
            $panicController->notifyFamily($space, $panicEvent, $request->tenant?->name);
        }

        return $genericResponse;
    }
}

