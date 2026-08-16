<?php

namespace App\Services;

use App\Models\EntityRead;
use App\Models\Entity;
use Illuminate\Support\Facades\Log;

class EntityReadProcessor
{
    /**
     * Processa regras de negócio associadas a uma leitura pública de QR Code.
     * Esta classe foi projetada para não quebrar a página pública caso alguma integração falhe.
     *
     * @param EntityRead $read
     * @param Entity $entity
     * @return void
     */
    public function process(EntityRead $read, Entity $entity): void
    {
        Log::info('EntityReadProcessor: iniciando processamento da leitura.', [
            'unique_code' => $entity->unique_code,
            'read_id'     => $read->id,
        ]);

        $this->detectReadSpike($read, $entity);
        $this->detectFirstReadToday($read, $entity);
        $this->applyRules($read, $entity);
    }

    private function detectReadSpike(EntityRead $read, Entity $entity): void
    {
        $recentReadsCount = EntityRead::where('entity_id', $entity->id)
            ->where('read_at', '>=', now()->subMinutes(15))
            ->count();

        if ($recentReadsCount >= 5) {
            $recentAlert = \Illuminate\Support\Facades\DB::table('entity_alerts')
                ->where('entity_id', $entity->id)
                ->where('type', 'read_spike')
                ->where('created_at', '>=', now()->subMinutes(15))
                ->exists();

            if (!$recentAlert) {
                \Illuminate\Support\Facades\DB::table('entity_alerts')->insert([
                    'entity_id' => $entity->id,
                    'type' => 'read_spike',
                    'payload' => json_encode(['reads_count' => $recentReadsCount]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function detectFirstReadToday(EntityRead $read, Entity $entity): void
    {
        $countReadsToday = EntityRead::where('entity_id', $entity->id)
            ->whereDate('read_at', now()->toDateString())
            ->count();

        if ($countReadsToday === 1) {
            $recentAlert = \Illuminate\Support\Facades\DB::table('entity_alerts')
                ->where('entity_id', $entity->id)
                ->where('type', 'first_read_today')
                ->whereDate('created_at', now()->toDateString())
                ->exists();

            if (!$recentAlert) {
                \Illuminate\Support\Facades\DB::table('entity_alerts')->insert([
                    'entity_id' => $entity->id,
                    'type' => 'first_read_today',
                    'payload' => json_encode(['read_at' => $read->read_at->toIso8601String()]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Ponto de extensão para futuras regras de notificação (ex.: push, e-mail) 
     * ou processamentos de localização avançados.
     */
    private function applyRules(EntityRead $read, Entity $entity): void
    {
        // TODO: Mapear se o tutor configurou notificação push ou email para cada leitura
        // TODO: Acionar NotificationService caso $this->shouldNotify($entity)
    }

    private function shouldNotify(Entity $entity): bool
    {
        // Placeholder para validar configurações do tenant/espaço
        return false;
    }
}
