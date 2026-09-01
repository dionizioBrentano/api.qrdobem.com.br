<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\CreditBatch;
use App\Models\Space;
use App\Policies\SpacePolicy;
use Illuminate\Http\Request;

class PositionController extends Controller
{
    public function store(Request $request, $unique_code)
    {
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);

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

        if (!empty($validated['device_id'])) {
            $entity->devices()
                   ->where('device_id', $validated['device_id'])
                   ->update(['last_seen_at' => now()]);
        }

        return response()->json($position, 201);
    }

    public function latest(Request $request, $unique_code)
    {
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);

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

