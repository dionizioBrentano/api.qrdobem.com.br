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

    public function activeWindows(Routine $routine, \Carbon\Carbon $at): \Illuminate\Support\Collection
    {
        $dayOfWeek = $at->dayOfWeek; // 0 (Domingo) a 6 (Sábado)

        return $routine->windows()->where('day_of_week', $dayOfWeek)->get()->filter(function ($window) use ($at) {
            $start = \Carbon\Carbon::parse($window->start_time)->setDate($at->year, $at->month, $at->day);
            $end = \Carbon\Carbon::parse($window->end_time)->setDate($at->year, $at->month, $at->day);

            $tolerance = $window->tolerance_minutes ?? 0;
            $start->subMinutes($tolerance);
            $end->addMinutes($tolerance);

            if ($end->lessThan($start)) {
                // Atravessa a meia-noite
                return $at->greaterThanOrEqualTo($start) || $at->lessThanOrEqualTo($end);
            }

            return $at->between($start, $end);
        })->values();
    }

    public function isIdle(Entity $entity, int $minutes, int $toleranceMeters): bool
    {
        $since = now()->subMinutes($minutes);

        $positions = $entity->positions()
            ->where('recorded_at', '>=', $since)
            ->orderBy('recorded_at', 'asc')
            ->get();

        if ($positions->count() < 3) {
            return false;
        }

        $first = $positions->first();

        foreach ($positions as $pos) {
            $dist = $this->distanceMeters(
                $first->latitude, $first->longitude,
                $pos->latitude, $pos->longitude
            );
            
            if ($dist > $toleranceMeters) {
                return false;
            }
        }

        return true;
    }

    public function companionDistance(Entity $entity): ?float
    {
        $freshMinutes = config('adventure.companion_fresh_minutes');
        $threshold = now()->subMinutes($freshMinutes);

        $protectedDevices = $entity->devices()->where('role', 'protected')->pluck('device_id');
        $companionDevices = $entity->devices()->where('role', 'companion')->pluck('device_id');

        if ($protectedDevices->isEmpty() || $companionDevices->isEmpty()) {
            return null;
        }

        $protectedPos = $entity->positions()
            ->whereIn('device_id', $protectedDevices)
            ->where('recorded_at', '>=', $threshold)
            ->orderByDesc('recorded_at')
            ->first();

        $companionPos = $entity->positions()
            ->whereIn('device_id', $companionDevices)
            ->where('recorded_at', '>=', $threshold)
            ->orderByDesc('recorded_at')
            ->first();

        if (!$protectedPos || !$companionPos) {
            return null;
        }

        return $this->distanceMeters(
            $protectedPos->latitude, $protectedPos->longitude,
            $companionPos->latitude, $companionPos->longitude
        );
    }

    public function shouldChargeIdle(Routine $routine, EntityPosition $position, \Carbon\Carbon $at): bool
    {
        $activeWindows = $this->activeWindows($routine, $at);

        foreach ($activeWindows as $window) {
            if ($window->expects_movement === false) {
                $isInside = false;
                
                if ($window->entity_reference_point_id) {
                    $point = $window->point;
                    if ($point) {
                        $distance = $this->distanceMeters(
                            $point->latitude, $point->longitude,
                            $position->latitude, $position->longitude
                        );
                        if ($distance <= $point->radius_meters) {
                            $isInside = true;
                        }
                    }
                } else {
                    if ($this->isInsideAnyPoint($routine, $position)) {
                        $isInside = true;
                    }
                }

                if ($isInside) {
                    return false;
                }
            }
        }

        return true;
    }

    private function createOffRoute(Entity $entity, EntityPosition $lastPosition, $routineId, $windowId = null): void
    {
        AdventureEvent::create([
            'entity_id' => $entity->id,
            'type' => 'wellness_check',
            'reason' => 'off_route',
            'status' => 'pending',
            'requested_at' => now(),
            'metadata' => array_filter([
                'latitude' => $lastPosition->latitude,
                'longitude' => $lastPosition->longitude,
                'routine_id' => $routineId,
                'routine_window_id' => $windowId,
            ]),
        ]);
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

        // Anti-spam (Único return cedo além do item 1)
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
            return;
        }

        $now = now();
        $offRouteCreated = false;

        foreach ($routines as $routine) {
            $windows = $routine->windows;

            // 3. PRESENÇA
            if ($windows->isEmpty()) {
                // a) rotina SEM nenhuma janela -> mantém o passo 10
                $isInside = $this->isInsideAnyPoint($routine, $lastPosition) !== null;

                if (!$isInside && !$offRouteCreated) {
                    $this->createOffRoute($entity, $lastPosition, $routine->id);
                    $offRouteCreated = true;
                }
            } else {
                $activeWindows = $this->activeWindows($routine, $now);

                if ($activeWindows->isEmpty()) {
                    // b) NENHUMA valendo agora -> NÃO cria off_route e SEGUE EM FRENTE.
                    continue;
                }

                // c) há janela valendo
                $isInsideActiveWindow = false;
                $failedWindowId = null;

                foreach ($activeWindows as $window) {
                    if ($window->entity_reference_point_id) {
                        // com entity_reference_point_id -> só aquele ponto conta
                        $point = $window->point;
                        if ($point) {
                            $distance = $this->distanceMeters(
                                $point->latitude, $point->longitude,
                                $lastPosition->latitude, $lastPosition->longitude
                            );
                            if ($distance <= $point->radius_meters) {
                                $isInsideActiveWindow = true;
                                break;
                            }
                        }
                    } else {
                        // sem ponto -> qualquer ponto da rotina
                        if ($this->isInsideAnyPoint($routine, $lastPosition)) {
                            $isInsideActiveWindow = true;
                            break;
                        }
                    }
                    $failedWindowId = $window->id;
                }

                if (!$isInsideActiveWindow && !$offRouteCreated) {
                    $isInsideAny = $this->isInsideAnyPoint($routine, $lastPosition) !== null;
                    
                    // skip_alert_inside_trail continua valendo quando está DENTRO.
                    if ($isInsideAny && $routine->skip_alert_inside_trail) {
                        // Está dentro da rotina, não cria alerta.
                    } else {
                        // fora -> wellness_check
                        $this->createOffRoute($entity, $lastPosition, $routine->id, $failedWindowId);
                        $offRouteCreated = true;
                    }
                }
            }
        }
        
        // 12. IMOBILIDADE
        // Hoje a decisão é só GPS; acelerômetro é melhoria futura do front.
        
        if (!$offRouteCreated) {
            $chargeIdle = true;
            foreach ($routines as $routine) {
                if (!$this->shouldChargeIdle($routine, $lastPosition, $now)) {
                    $chargeIdle = false;
                    break;
                }
            }

            if ($chargeIdle) {
                $idleMinutes = config('adventure.idle_minutes');
                $idleTolerance = config('adventure.idle_tolerance_meters');

                if ($this->isIdle($entity, $idleMinutes, $idleTolerance)) {
                    AdventureEvent::create([
                        'entity_id' => $entity->id,
                        'type' => 'wellness_check',
                        'reason' => 'idle',
                        'status' => 'pending',
                        'requested_at' => now(),
                        'metadata' => [
                            'latitude' => $lastPosition->latitude,
                            'longitude' => $lastPosition->longitude,
                        ],
                    ]);
                    $offRouteCreated = true;
                }
            }
        }
        
        // 14. AFASTAMENTO DO ACOMPANHANTE
        if (!$offRouteCreated) {
            $dist = $this->companionDistance($entity);
            if ($dist !== null && $dist > config('adventure.companion_max_meters')) {
                // Fetch devices again to put in metadata
                $freshMinutes = config('adventure.companion_fresh_minutes');
                $threshold = now()->subMinutes($freshMinutes);
                
                $protectedPos = $entity->positions()
                    ->whereIn('device_id', $entity->devices()->where('role', 'protected')->pluck('device_id'))
                    ->where('recorded_at', '>=', $threshold)
                    ->orderByDesc('recorded_at')
                    ->first();

                $companionPos = $entity->positions()
                    ->whereIn('device_id', $entity->devices()->where('role', 'companion')->pluck('device_id'))
                    ->where('recorded_at', '>=', $threshold)
                    ->orderByDesc('recorded_at')
                    ->first();

                AdventureEvent::create([
                    'entity_id' => $entity->id,
                    'type' => 'wellness_check',
                    'reason' => 'companion_far',
                    'status' => 'pending',
                    'requested_at' => now(),
                    'metadata' => [
                        'distance_meters' => $dist,
                        'protected_device_id' => $protectedPos?->device_id,
                        'companion_device_id' => $companionPos?->device_id,
                        'latitude' => $protectedPos?->latitude,
                        'longitude' => $protectedPos?->longitude,
                    ],
                ]);
            }
        }
    }

    public function escalatePending(): void
    {
        $escalateMinutes = config('adventure.escalate_minutes');
        $threshold = now()->subMinutes($escalateMinutes);

        $events = AdventureEvent::with('entity.space')->where('type', 'wellness_check')
            ->where('status', 'pending')
            ->where('requested_at', '<=', $threshold)
            ->get();

        if ($events->isEmpty()) {
            return;
        }

        $dispatcher = app(\App\Services\Notification\NotificationDispatcher::class);

        foreach ($events as $event) {
            $event->update(['status' => 'escalated']);

            $entity = $event->entity;
            if (!$entity) {
                continue;
            }

            $space = $entity->space;

            $reasonText = match ($event->reason) {
                'off_route' => 'fora da rota ou área esperada',
                'idle' => 'sem movimentação pelo tempo limite',
                'companion_far' => 'distante do acompanhante',
                'manual' => 'acionado manualmente',
                default => 'motivo desconhecido'
            };

            $lat = $event->metadata['latitude'] ?? null;
            $lng = $event->metadata['longitude'] ?? null;
            
            $mapsUrl = ($lat && $lng) ? "https://maps.google.com/?q={$lat},{$lng}" : null;
            $locationText = ($lat && $lng) ? "{$lat}, {$lng}" : 'não informada';

            $body = "ALERTA DE CHECAGEM SEM RESPOSTA\n\n"
                  . "Pessoa protegida: {$entity->name}\n"
                  . "Motivo: {$reasonText}\n"
                  . "Última posição: {$locationText}\n";

            if ($mapsUrl) {
                $body .= "Mapa: {$mapsUrl}\n";
            }

            $message = new \App\Services\Notification\NotificationMessage(
                subject: 'Alerta: Checagem de Trilha sem resposta',
                body: $body,
                template: 'wellness_escalation',
                templateData: [$entity->name, $reasonText, $mapsUrl ?? 'não informada'],
                url: $mapsUrl,
                priority: 'high'
            );

            // 1. Membros do Space
            $memberTenantIds = collect();
            if ($space) {
                $memberTenantIds = \App\Models\SpaceMember::where('space_id', $space->id)
                    ->whereNotNull('accepted_at')
                    ->pluck('tenant_id')
                    ->push($space->owner_tenant_id)
                    ->unique()
                    ->values();
            }
            $members = \App\Models\Tenant::whereIn('id', $memberTenantIds)->get();

            // 2. Contatos de Emergência
            $emergencyContacts = \App\Models\EmergencyContact::where('status', 'accepted')
                ->where('entity_id', $entity->id)
                ->get();

            $results = [];

            foreach ($members as $member) {
                $destinations = array_filter([
                    'push'     => $member->push_subscription ? json_encode($member->push_subscription) : null,
                    'mail'     => $member->email,
                    'whatsapp' => $member->phone,
                ]);

                if (empty($destinations)) {
                    continue;
                }

                $result = $dispatcher->sendVia($destinations, ['push', 'mail', 'whatsapp'], $message);
                $results[] = [
                    'tenant_id' => $member->id,
                    'name'      => $member->name,
                    'channel'   => $result->channel,
                    'success'   => $result->success,
                ];
            }

            foreach ($emergencyContacts as $contact) {
                $destinations = array_filter([
                    'push'     => $contact->push_subscription ? json_encode($contact->push_subscription) : null,
                    'mail'     => $contact->email,
                ]);

                if (empty($destinations) && $contact->email) {
                    $destinations['mail'] = $contact->email;
                }

                if (empty($destinations)) {
                    continue;
                }

                $result = $dispatcher->sendVia($destinations, ['push', 'mail'], $message);
                $results[] = [
                    'contact_id' => $contact->id,
                    'name'       => $contact->name,
                    'channel'    => $result->channel,
                    'success'    => $result->success,
                ];
            }

            $metadata = $event->metadata ?? [];
            $metadata['escalation_results'] = $results;
            $event->update(['metadata' => $metadata]);
        }
    }
}
