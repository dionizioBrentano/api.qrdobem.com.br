<?php

namespace App\Services;

use App\Models\Entity;
use App\Models\EntityPosition;
use App\Models\Routine;
use App\Models\EntityReferencePoint;
use App\Models\AdventureEvent;

class AdventureMonitorService
{
    /**
     * Calcula a distância em metros entre duas coordenadas via fórmula de Haversine.
     */
    public function distanceMeters($lat1, $lon1, $lat2, $lon2): float
    {
        $earthRadius = 6371000; // metros

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($lonDelta / 2) * sin($lonDelta / 2);
             
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    public function isInsideAnyPoint(Routine $routine, EntityPosition $position): ?EntityReferencePoint
    {
        foreach ($routine->points as $point) {
            $distance = $this->distanceMeters(
                $point->latitude,
                $point->longitude,
                $position->latitude,
                $position->longitude
            );

            if ($distance <= $point->radius_meters) {
                return $point;
            }
        }

        return null;
    }

    public function evaluate(Entity $entity): void
    {
        // 1. Última posição
        $lastPosition = $entity->positions()->orderBy('recorded_at', 'desc')->first();
        if (!$lastPosition) {
            return;
        }

        $maxAgeMinutes = config('adventure.position_max_age_minutes');
        if ($lastPosition->recorded_at->diffInMinutes(now()) > $maxAgeMinutes) {
            return;
        }

        // 5. ANTI-SPAM (Único return cedo além do item 1)
        $hasPendingCheck = AdventureEvent::where('entity_id', $entity->id)
            ->where('type', 'wellness_check')
            ->where('status', 'pending')
            ->exists();

        if ($hasPendingCheck) {
            return;
        }

        // 2. Rotinas ativas da entity
        $routines = $entity->routines()->where('is_active', true)->get();
        if ($routines->isEmpty()) {
            return; // Se não tem rotina ativa, não avalia. (ou o prompt não diz para retornar aqui, mas se não há rotina não há ponto)
        }

        $insideTrail = false;
        $skipAlert = false;
        $routineIdForMetadata = $routines->first()->id;

        foreach ($routines as $routine) {
            $point = $this->isInsideAnyPoint($routine, $lastPosition);
            if ($point) {
                $insideTrail = true;
                if ($routine->skip_alert_inside_trail) {
                    $skipAlert = true;
                }
            }
        }

        // 3. PRESENÇA
        // Se achou ponto e skip_alert_inside_trail for true -> NÃO cria off_route.
        // Este if não dá return/break para permitir passos 12 e 14 depois.
        if ($insideTrail && $skipAlert) {
            // Futuros checks de horário são ignorados aqui
        } else {
            // 4. Fora de todos os pontos -> cria off_route
            if (!$insideTrail) {
                AdventureEvent::create([
                    'entity_id' => $entity->id,
                    'type' => 'wellness_check',
                    'reason' => 'off_route',
                    'status' => 'pending',
                    'requested_at' => now(),
                    'metadata' => [
                        'latitude' => $lastPosition->latitude,
                        'longitude' => $lastPosition->longitude,
                        'routine_id' => $routineIdForMetadata,
                    ],
                ]);
            }
        }
    }
}
