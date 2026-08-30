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

        return response()->json($entity->devices);
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
        $entity = $this->resolveEntity($request, $unique_code);

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
        $entity = $this->resolveEntity($request, $unique_code);

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
        $entity = $this->resolveEntity($request, $unique_code);

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
        $entity = $this->resolveEntity($request, $unique_code);

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
