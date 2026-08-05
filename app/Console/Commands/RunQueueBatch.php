<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * queue:batch — processa a fila e encerra. Feito para cron de hospedagem
 * compartilhada. Fundação F3 do PLANO_TRILHAS_2026-08.md (entrega 0.3).
 *
 * POR QUE ISTO EXISTE
 * O CPanel não roda daemon nem Supervisor, então `queue:work` contínuo não
 * é opção. Sem fila, o Botão de Pânico (T1-R07) teria de disparar N
 * mensagens de WhatsApp dentro da request HTTP — o alerta chegaria depois
 * do timeout, ou não chegaria. O mesmo vale para geração de QR em lote
 * (T2-R03) e e-mail em volume.
 *
 * COMO FUNCIONA
 * Cron chama este comando a cada minuto. Ele processa o que houver e sai
 * antes do minuto acabar, para não haver dois processos concorrendo. Um
 * lock em cache garante isso mesmo se uma execução demorar mais que o
 * previsto.
 *
 * CONFIGURAR NO CPANEL (Cron Jobs), uma linha, a cada minuto:
 *
 *   * * * * * cd ~/api.qrdobem.com.br && /usr/local/bin/php artisan queue:batch >> storage/logs/queue.log 2>&1
 *
 * Confirmar antes o caminho real do PHP com `which php` no SSH.
 *
 * EXIGE no .env do servidor:
 *   QUEUE_CONNECTION=database
 * e a tabela `jobs`, que já existe (migration 0001_01_01_000002).
 * Com QUEUE_CONNECTION=sync (padrão atual) nada é enfileirado: os jobs
 * rodam na hora, dentro da request — exatamente o que se quer evitar.
 */
class RunQueueBatch extends Command
{
    protected $signature = 'queue:batch
                            {--max-time=50 : Segundos de processamento antes de encerrar}
                            {--tries=3 : Tentativas por job antes de falhar}';

    protected $description = 'Processa a fila e encerra — desenhado para cron de hospedagem compartilhada';

    /** Chave do lock que impede duas execuções simultâneas. */
    private const LOCK_KEY = 'queue-batch-running';

    public function handle(): int
    {
        $maxTime = (int) $this->option('max-time');

        // Lock com expiração um pouco maior que o tempo de execução: se o
        // processo morrer sem liberar, o próximo minuto ainda destrava.
        $lock = Cache::lock(self::LOCK_KEY, $maxTime + 10);

        if (!$lock->get()) {
            $this->info('Outra execução ainda está rodando. Saindo.');
            return self::SUCCESS;
        }

        try {
            if (config('queue.default') === 'sync') {
                $this->warn('QUEUE_CONNECTION=sync — nada a processar; os jobs rodam na request.');
                $this->warn('Defina QUEUE_CONNECTION=database no .env e rode php artisan config:cache.');
                return self::SUCCESS;
            }

            $this->info('Processando fila por até ' . $maxTime . 's...');

            Artisan::call('queue:work', [
                '--stop-when-empty' => true,
                '--max-time' => $maxTime,
                '--tries' => (int) $this->option('tries'),
                // Sem isso, alteração de código só passa a valer no próximo
                // deploy da fila — e aqui cada execução é um processo novo.
                '--no-interaction' => true,
            ], $this->getOutput());

            return self::SUCCESS;
        } finally {
            // `finally` para o lock ser liberado mesmo se o worker estourar.
            $lock->release();
        }
    }
}
