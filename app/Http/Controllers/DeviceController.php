<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\CreditBatch;
use App\Models\Space;
use App\Policies\SpacePolicy;
use Illuminate\Http\Request;
use App\Models\EntityDevice;

class DeviceController extends Controller
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

        return response()->json($entity->devices);
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

        $validated = $request->validate([
            'device_id' => 'required|string|max:64',
            'label' => 'nullable|string|max:80',
            'role' => 'required|in:protected,companion',
        ]);

        $device = EntityDevice::updateOrCreate(
            [
                'entity_id' => $entity->id,
                'device_id' => $validated['device_id'],
            ],
            [
                'label' => $validated['label'] ?? null,
                'role' => $validated['role'],
                'last_seen_at' => now(),
            ]
        );

        return response()->json($device, 201);
    }

    public function update(Request $request, $unique_code, $device_id)
    {
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);

        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        if ($entity->type !== 'person') {
            return response()->json(['error' => 'Trilha Aventura suportada apenas para pessoas.'], 400);
        }

        $device = $entity->devices()->findOrFail($device_id);

        $validated = $request->validate([
            'label' => 'nullable|string|max:80',
            'role' => 'required|in:protected,companion',
        ]);

        $device->update($validated);

        return response()->json($device);
    }

    public function destroy(Request $request, $unique_code, $device_id)
    {
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);

        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        if ($entity->type !== 'person') {
            return response()->json(['error' => 'Trilha Aventura suportada apenas para pessoas.'], 400);
        }

        $device = $entity->devices()->findOrFail($device_id);
        $device->delete();

        return response()->json(['message' => 'Dispositivo removido com sucesso.']);
    }

    public function issueToken(Request $request, $unique_code, $device_id)
    {
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);

        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        if ($entity->type !== 'person') {
            return response()->json(['error' => 'Trilha Aventura suportada apenas para pessoas.'], 400);
        }

        $device = $entity->devices()->findOrFail($device_id);

        $plainTextToken = \Illuminate\Support\Str::random(40);
        $hashedToken = hash('sha256', $plainTextToken);

        $device->update([
            'token_hash' => $hashedToken,
            'token_expires_at' => null, // Never expires by default, can be revoked
        ]);

        return response()->json([
            'device' => $device,
            'token' => $plainTextToken
        ]);
    }

    public function revokeToken(Request $request, $unique_code, $device_id)
    {
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);

        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        if ($entity->type !== 'person') {
            return response()->json(['error' => 'Trilha Aventura suportada apenas para pessoas.'], 400);
        }

        $device = $entity->devices()->findOrFail($device_id);

        $device->update([
            'token_hash' => null,
            'token_expires_at' => null,
        ]);

        return response()->json(['message' => 'Token revogado com sucesso.']);
    }
}

