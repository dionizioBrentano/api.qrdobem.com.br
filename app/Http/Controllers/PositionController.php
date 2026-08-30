<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\CreditBatch;
use App\Models\Space;
use App\Policies\SpacePolicy;
use Illuminate\Http\Request;

class PositionController extends Controller
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

    public function store(Request $request, $unique_code)
    {
        $entity = $this->resolveEntity($request, $unique_code);

        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        if ($entity->type !== 'person') {
            return response()->json(['error' => 'Trilha Aventura suportada apenas para pessoas.'], 400);
        }

        $validated = $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'accuracy_meters' => 'nullable|integer|min:0',
            'recorded_at' => 'nullable|date',
            'device_id' => 'nullable|string|max:64',
        ]);

        if (empty($validated['recorded_at'])) {
            $validated['recorded_at'] = now();
        }

        $position = $entity->positions()->create($validated);

        return response()->json($position, 201);
    }

    public function latest(Request $request, $unique_code)
    {
        $entity = $this->resolveEntity($request, $unique_code);

        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        if ($entity->type !== 'person') {
            return response()->json(['error' => 'Trilha Aventura suportada apenas para pessoas.'], 400);
        }

        $position = $entity->positions()->orderBy('recorded_at', 'desc')->first();

        if (!$position) {
            return response()->json(null);
        }

        return response()->json($position);
    }
}
