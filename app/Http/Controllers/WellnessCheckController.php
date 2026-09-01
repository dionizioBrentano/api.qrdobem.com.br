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
    public function index(Request $request, $unique_code)
    {
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);

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
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);

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
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);

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
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);

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

    public function spaceIndex(Request $request, $spaceId)
    {
        $space = Space::find($spaceId);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'entity.view');

        $entityIds = Entity::where('type', 'person')
            ->where(function ($query) use ($space) {
                $query->where('space_id', $space->id);
                if ($space->organization_id) {
                    $query->orWhere('organization_id', $space->organization_id);
                }
            })
            ->pluck('id');

        $query = AdventureEvent::with('entity')
            ->whereIn('entity_id', $entityIds)
            ->where('type', 'wellness_check')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('reason')) {
            $query->where('reason', $request->input('reason'));
        }

        if ($request->filled('from')) {
            $query->whereDate('requested_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('requested_at', '<=', $request->input('to'));
        }

        $checks = $query->paginate();

        $checks->getCollection()->transform(function ($check) {
            return [
                'id' => $check->id,
                'entity' => [
                    'unique_code' => $check->entity->unique_code,
                    'name' => $check->entity->encrypted_name,
                ],
                'reason' => $check->reason,
                'status' => $check->status,
                'requested_at' => $check->requested_at ? $check->requested_at->toIso8601String() : null,
                'responded_at' => $check->responded_at ? $check->responded_at->toIso8601String() : null,
                'latitude' => $check->metadata['latitude'] ?? null,
                'longitude' => $check->metadata['longitude'] ?? null,
            ];
        });

        return response()->json($checks);
    }
}

