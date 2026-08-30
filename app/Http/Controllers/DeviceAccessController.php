<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AdventureEvent;
use Illuminate\Validation\Rule;

class DeviceAccessController extends Controller
{
    public function storePosition(Request $request)
    {
        $entity = $request->attributes->get('entity');
        $device = $request->attributes->get('device');

        $validated = $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'accuracy_meters' => 'nullable|integer|min:0',
            'recorded_at' => 'nullable|date',
        ]);

        if (empty($validated['recorded_at'])) {
            $validated['recorded_at'] = now();
        }

        $validated['device_id'] = $device->device_id;

        $position = $entity->positions()->create($validated);

        $device->update(['last_seen_at' => now()]);

        return response()->json($position, 201);
    }

    public function latestPosition(Request $request)
    {
        $entity = $request->attributes->get('entity');

        $position = $entity->positions()->orderBy('recorded_at', 'desc')->first();

        if (!$position) {
            return response()->json(null);
        }

        return response()->json($position);
    }

    public function pendingWellnessCheck(Request $request)
    {
        $entity = $request->attributes->get('entity');

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

    public function respondWellnessCheck(Request $request, $check_id)
    {
        $entity = $request->attributes->get('entity');

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

    public function updateDevice(Request $request)
    {
        $device = $request->attributes->get('device');

        $validated = $request->validate([
            'label' => 'nullable|string|max:80',
            'role' => ['required', Rule::in(['protected', 'companion'])],
        ]);

        $device->update($validated);

        return response()->json($device);
    }
}
