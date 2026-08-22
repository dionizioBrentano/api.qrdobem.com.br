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
    /**
     * Helper para checar acesso à entidade seguindo a mesma lógica do EntityController.
     */
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

    /**
     * Tenta resolver a entidade e verificar a autorização.
     * Retorna a Entity ou null se falhar/negado.
     */
    private function resolveEntity(Request $request, $unique_code)
    {
        $tenant = $request->tenant;
        $entity = Entity::where('unique_code', $unique_code)->first();

        if (!$entity || !$this->canAccessEntity($tenant, $entity)) {
            return null;
        }

        return $entity;
    }

    public function listReferencePoints(Request $request, $unique_code)
    {
        $entity = $this->resolveEntity($request, $unique_code);

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
        $entity = $this->resolveEntity($request, $unique_code);

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
            'radius_meters' => 'nullable|integer|min:10',
            'days_of_week' => 'nullable|array',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
        ]);

        if (!isset($validated['radius_meters'])) {
            $validated['radius_meters'] = 50;
        }

        $point = $entity->referencePoints()->create($validated);

        return response()->json($point, 201);
    }

    public function destroyReferencePoint(Request $request, $unique_code, $point_id)
    {
        $entity = $this->resolveEntity($request, $unique_code);

        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        $point = $entity->referencePoints()->where('id', $point_id)->firstOrFail();
        $point->delete();

        return response()->json(['message' => 'Ponto de referência removido com sucesso.']);
    }

    public function setSilentPassword(Request $request, $unique_code)
    {
        $entity = $this->resolveEntity($request, $unique_code);

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
}
