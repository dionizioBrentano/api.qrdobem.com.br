<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\CreditBatch;
use App\Models\Space;
use App\Models\AdventureEvent;
use App\Policies\SpacePolicy;
use Illuminate\Http\Request;

class WellnessCheckController extends Controller
{
    private function canAccessEntity($tenant, Entity $entity): bool
    {
        $orgIds = $tenant->organizations()->pluck('organizations.id')->all();

        if ($entity->organization_id && in_array($entity->organization_id, $orgIds)) {
            return true;
        }

        if (!$entity->organization_id && $entity->credit_batch_id) {
            $batch = CreditBatch::find($entity->credit_batch_id);
            if ($batch && $batch->recipient_tenant_id === $tenant->id) {
                return true;
            }
        }

        if (!$entity->space_id) {
            return false;
        }

        try {
            $space = Space::find($entity->space_id);

            return $space
                ? app(SpacePolicy::class)->check($tenant, $space, 'entity.view')
                : false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function resolveEntity(Request $request, $unique_code)
    {
        $tenant = $request->tenant;
        $entity = Entity::where('unique_code', $unique_code)->first();

        if (!$entity || !$this->canAccessEntity($tenant, $entity)) {
            return null;
        }

        return $entity;
    }

    public function index(Request $request, $unique_code)
    {
        $entity = $this->resolveEntity($request, $unique_code);

        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        if ($entity->type !== 'person') {
            return response()->json(['error' => 'Trilha Aventura suportada apenas para pessoas.'], 400);
        }

        $checks = AdventureEvent::where('entity_id', $entity->id)
            ->where('type', 'wellness_check')
            ->orderBy('id', 'desc')
            ->paginate();

        return response()->json($checks);
    }

    public function pending(Request $request, $unique_code)
    {
        $entity = $this->resolveEntity($request, $unique_code);

        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        if ($entity->type !== 'person') {
            return response()->json(['error' => 'Trilha Aventura suportada apenas para pessoas.'], 400);
        }

        $pending = AdventureEvent::where('entity_id', $entity->id)
            ->where('type', 'wellness_check')
            ->where('status', 'pending')
            ->orderBy('id', 'desc')
            ->first();

        if (!$pending) {
            return response()->json(null);
        }

        return response()->json($pending);
    }

    public function store(Request $request, $unique_code)
    {
        $entity = $this->resolveEntity($request, $unique_code);

        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        if ($entity->type !== 'person') {
            return response()->json(['error' => 'Trilha Aventura suportada apenas para pessoas.'], 400);
        }

        $check = AdventureEvent::create([
            'entity_id' => $entity->id,
            'type' => 'wellness_check',
            'status' => 'pending',
            'reason' => 'manual',
            'requested_at' => now(),
            'device_id' => $request->input('device_id'),
        ]);

        return response()->json($check, 201);
    }

    public function respond(Request $request, $unique_code, $check_id)
    {
        $entity = $this->resolveEntity($request, $unique_code);

        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        if ($entity->type !== 'person') {
            return response()->json(['error' => 'Trilha Aventura suportada apenas para pessoas.'], 400);
        }

        $check = AdventureEvent::where('id', $check_id)
            ->where('entity_id', $entity->id)
            ->where('type', 'wellness_check')
            ->firstOrFail();

        if ($check->status !== 'pending') {
            return response()->json(['error' => 'Esta checagem não está mais pendente.'], 409);
        }

        $check->update([
            'status' => 'ok',
            'responded_at' => now(),
        ]);

        return response()->json($check);
    }
}
