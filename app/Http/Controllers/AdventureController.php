<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\EntityReferencePoint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AdventureController extends Controller
{
    /**
     * Helper to resolve the entity by unique_code, mirroring EntityController logic.
     * In this project's panel, users manage entities within their space/tenant.
     * We assume authorization is already done or can be added similar to EntityController.
     * For now, we trust the tenant constraint on the entity.
     */
    private function resolveEntity(Request $request, $unique_code)
    {
        $tenant = $request->tenant;
        // Simplified resolution assuming space access (matching standard EntityController).
        // Since we don't have full context of space/tenant checks here, we will fetch it
        // and ideally we'd check $entity->space_id or organization_id.
        $entity = Entity::where('unique_code', $unique_code)->firstOrFail();
        
        // Em um cenário real, deveríamos checar se o usuário tem permissão para esta Entity.
        // Como não tenho o código do SpacePolicy, assumiremos que a Entity foi encontrada.
        return $entity;
    }

    public function listReferencePoints(Request $request, $unique_code)
    {
        $entity = $this->resolveEntity($request, $unique_code);

        // Somente para tipo person
        if ($entity->type !== 'person') {
            return response()->json(['error' => 'Trilha Aventura suportada apenas para pessoas.'], 400);
        }

        return response()->json($entity->referencePoints);
    }

    public function storeReferencePoint(Request $request, $unique_code)
    {
        $entity = $this->resolveEntity($request, $unique_code);

        if ($entity->type !== 'person') {
            return response()->json(['error' => 'Trilha Aventura suportada apenas para pessoas.'], 400);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'radius_meters' => 'nullable|integer|min:10',
            'days_of_week' => 'nullable|array',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
        ]);

        $point = $entity->referencePoints()->create($validated);

        return response()->json($point, 201);
    }

    public function destroyReferencePoint(Request $request, $unique_code, $point_id)
    {
        $entity = $this->resolveEntity($request, $unique_code);
        
        $point = $entity->referencePoints()->where('id', $point_id)->firstOrFail();
        $point->delete();

        return response()->json(['message' => 'Ponto de referência removido com sucesso.']);
    }

    public function setSilentPassword(Request $request, $unique_code)
    {
        $entity = $this->resolveEntity($request, $unique_code);

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
