<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use App\Models\Entity;
use App\Services\AdventureMonitorService;

/**
 * adventure:monitor — avalia rotinas da Trilha Aventura para pessoas protegidas.
 *
 * CONFIGURAR NO CPANEL (Cron Jobs), uma linha, a cada minuto:
 *
 * * * * * * cd ~/api.qrdobem.com.br && /usr/local/bin/php artisan adventure:monitor >> storage/logs/adventure.log 2>&1
 */
class RunAdventureMonitor extends Command
{
    protected $signature = 'adventure:monitor
                            {--max-time=50 : Segundos de processamento antes de encerrar}';

    protected $description = 'Avalia regras e cria eventos (Estou bem, emergências) para Trilha Aventura';

    private const LOCK_KEY = 'adventure-monitor-running';

    public function handle(AdventureMonitorService $monitorService): int
    {
        $maxTime = (int) $this->option('max-time');
        
        $lock = Cache::lock(self::LOCK_KEY, $maxTime + 10);

        if (!$lock->get()) {
            $this->info('Outra execução ainda está rodando. Saindo.');
            return self::SUCCESS;
        }

        try {
            $this->info('Avaliando Trilha Aventura...');

            $evaluatedCount = 0;
            $createdChecksCount = 0;

            // Itera entity type=person COM pelo menos uma rotina ativa
            $entities = Entity::where('type', 'person')
                ->whereHas('routines', function ($query) {
                    $query->where('is_active', true);
                })
                ->get();

            foreach ($entities as $entity) {
                // Checa quantos pending havia antes
                $pendingBefore = \App\Models\AdventureEvent::where('entity_id', $entity->id)
                    ->where('type', 'wellness_check')
                    ->where('status', 'pending')
                    ->count();

                $monitorService->evaluate($entity);
                $evaluatedCount++;

                $pendingAfter = \App\Models\AdventureEvent::where('entity_id', $entity->id)
                    ->where('type', 'wellness_check')
                    ->where('status', 'pending')
                    ->count();

                if ($pendingAfter > $pendingBefore) {
                    $createdChecksCount += ($pendingAfter - $pendingBefore);
                }
            }

            $this->info("Total avaliado: {$evaluatedCount}");
            $this->info("Checagens criadas: {$createdChecksCount}");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
