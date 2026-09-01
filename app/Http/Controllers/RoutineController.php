<?php

namespace App\Http\Controllers;

use App\Models\CreditBatch;
use App\Models\Entity;
use App\Models\EntityReferencePoint;
use App\Models\Routine;
use App\Models\RoutineWindow;
use App\Models\Space;
use App\Policies\SpacePolicy;
use Illuminate\Http\Request;

class RoutineController extends Controller
{
    private function personOrFail(Entity $entity)
    {
        if ($entity->type !== 'person') {
            return response()->json(['error' => 'Trilha Aventura suportada apenas para pessoas.'], 400);
        }

        return null;
    }

    private function routineOf(Entity $entity, $routineId): ?Routine
    {
        return $entity->routines()->where('id', $routineId)->first();
    }

    public function index(Request $request, $unique_code)
    {
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);
        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }
        if ($resp = $this->personOrFail($entity)) {
            return $resp;
        }

        return response()->json($entity->routines()->with(['points', 'windows'])->get());
    }

    public function store(Request $request, $unique_code)
    {
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);
        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }
        if ($resp = $this->personOrFail($entity)) {
            return $resp;
        }

        if ($entity->routines()->where('is_active', true)->exists()) {
            return response()->json(['error' => 'Esta pessoa já tem uma trilha ativa.'], 422);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'is_active' => 'nullable|boolean',
            'skip_alert_inside_trail' => 'nullable|boolean',
            'space_id' => 'nullable|integer',
        ]);

        if (array_key_exists('space_id', $validated) && $validated['space_id'] !== null) {
            if ((int) $validated['space_id'] !== (int) $entity->space_id) {
                return response()->json(['error' => 'space_id não pertence a esta entidade.'], 422);
            }
        } else {
            $validated['space_id'] = $entity->space_id;
        }

        $validated['entity_id'] = $entity->id;
        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['skip_alert_inside_trail'] = $validated['skip_alert_inside_trail'] ?? true;

        $routine = Routine::create($validated);

        return response()->json($routine, 201);
    }

    public function update(Request $request, $unique_code, $routine_id)
    {
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);
        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }
        if ($resp = $this->personOrFail($entity)) {
            return $resp;
        }

        $routine = $this->routineOf($entity, $routine_id);
        if (!$routine) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'is_active' => 'nullable|boolean',
            'skip_alert_inside_trail' => 'nullable|boolean',
            'space_id' => 'nullable|integer',
        ]);

        if (array_key_exists('space_id', $validated) && $validated['space_id'] !== null) {
            if ((int) $validated['space_id'] !== (int) $entity->space_id) {
                return response()->json(['error' => 'space_id não pertence a esta entidade.'], 422);
            }
        }

        $routine->update($validated);

        return response()->json($routine->fresh());
    }

    public function destroy(Request $request, $unique_code, $routine_id)
    {
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);
        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        $routine = $this->routineOf($entity, $routine_id);
        if (!$routine) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        EntityReferencePoint::where('routine_id', $routine->id)->delete();
        $routine->delete();

        return response()->json(['message' => 'Trilha removida com sucesso.']);
    }

    public function listPoints(Request $request, $unique_code, $routine_id)
    {
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);
        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        $routine = $this->routineOf($entity, $routine_id);
        if (!$routine) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        return response()->json($routine->points);
    }

    public function storePoint(Request $request, $unique_code, $routine_id)
    {
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);
        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }
        if ($resp = $this->personOrFail($entity)) {
            return $resp;
        }

        $routine = $this->routineOf($entity, $routine_id);
        if (!$routine) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'address' => 'nullable|string|max:255',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'radius_meters' => 'nullable|integer|min:10',
            'order_index' => 'nullable|integer|min:0',
        ]);

        $validated['entity_id'] = $entity->id;
        $validated['routine_id'] = $routine->id;
        $validated['radius_meters'] = $validated['radius_meters'] ?? 50;
        $validated['order_index'] = $validated['order_index'] ?? 0;

        $point = EntityReferencePoint::create($validated);

        return response()->json($point, 201);
    }

    public function updatePoint(Request $request, $unique_code, $routine_id, $point_id)
    {
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);
        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        $routine = $this->routineOf($entity, $routine_id);
        if (!$routine) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        $point = $routine->points()->where('id', $point_id)->first();
        if (!$point) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'address' => 'nullable|string|max:255',
            'latitude' => 'sometimes|required|numeric',
            'longitude' => 'sometimes|required|numeric',
            'radius_meters' => 'nullable|integer|min:10',
            'order_index' => 'nullable|integer|min:0',
        ]);

        $point->update($validated);

        return response()->json($point->fresh());
    }

    public function destroyPoint(Request $request, $unique_code, $routine_id, $point_id)
    {
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);
        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        $routine = $this->routineOf($entity, $routine_id);
        if (!$routine) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        $point = $routine->points()->where('id', $point_id)->first();
        if (!$point) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        $point->delete();

        return response()->json(['message' => 'Ponto removido com sucesso.']);
    }

    public function listWindows(Request $request, $unique_code, $routine_id)
    {
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);
        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        $routine = $this->routineOf($entity, $routine_id);
        if (!$routine) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        return response()->json($routine->windows);
    }

    public function storeWindow(Request $request, $unique_code, $routine_id)
    {
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);
        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        $routine = $this->routineOf($entity, $routine_id);
        if (!$routine) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        $validated = $request->validate([
            'entity_reference_point_id' => 'nullable|integer',
            'day_of_week' => 'required|integer|between:0,6',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'tolerance_minutes' => 'nullable|integer|min:0|max:240',
            'expects_movement' => 'nullable|boolean',
        ]);

        if (!empty($validated['entity_reference_point_id'])) {
            $belongs = $routine->points()
                ->where('id', $validated['entity_reference_point_id'])
                ->exists();
            if (!$belongs) {
                return response()->json(['error' => 'Ponto não pertence a esta trilha.'], 422);
            }
        }

        $validated['routine_id'] = $routine->id;
        $validated['tolerance_minutes'] = $validated['tolerance_minutes'] ?? 15;
        $validated['expects_movement'] = $validated['expects_movement'] ?? true;

        $window = RoutineWindow::create($validated);

        return response()->json($window, 201);
    }

    public function updateWindow(Request $request, $unique_code, $routine_id, $window_id)
    {
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);
        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        $routine = $this->routineOf($entity, $routine_id);
        if (!$routine) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        $window = $routine->windows()->where('id', $window_id)->first();
        if (!$window) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        $validated = $request->validate([
            'entity_reference_point_id' => 'nullable|integer',
            'day_of_week' => 'sometimes|required|integer|between:0,6',
            'start_time' => 'sometimes|required|date_format:H:i',
            'end_time' => 'sometimes|required|date_format:H:i',
            'tolerance_minutes' => 'nullable|integer|min:0|max:240',
            'expects_movement' => 'nullable|boolean',
        ]);

        if (array_key_exists('entity_reference_point_id', $validated) && $validated['entity_reference_point_id']) {
            $belongs = $routine->points()
                ->where('id', $validated['entity_reference_point_id'])
                ->exists();
            if (!$belongs) {
                return response()->json(['error' => 'Ponto não pertence a esta trilha.'], 422);
            }
        }

        $window->update($validated);

        return response()->json($window->fresh());
    }

    public function destroyWindow(Request $request, $unique_code, $routine_id, $window_id)
    {
        $entity = app(\App\Services\EntityAccessService::class)->resolveEntity($request->tenant, $unique_code);
        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        $routine = $this->routineOf($entity, $routine_id);
        if (!$routine) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        $window = $routine->windows()->where('id', $window_id)->first();
        if (!$window) {
            return response()->json(['error' => 'Registro não encontrado ou acesso negado.'], 404);
        }

        $window->delete();

        return response()->json(['message' => 'Janela removida com sucesso.']);
    }
}

