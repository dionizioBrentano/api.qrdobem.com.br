<?php

namespace App\Services\Notification;

use Illuminate\Support\Facades\Log;

/**
 * NotificationDispatcher — ponto único de envio de notificação do sistema.
 * Fundação F4 do PLANO_TRILHAS_2026-08.md (entrega 0.4).
 *
 * REGRA: nenhum controller, job ou service chama canal concreto. Todos
 * chamam este dispatcher. É o que permite acrescentar WhatsApp na Fase 2
 * (T1-R07) sem tocar em quem dispara, e trocar de provedor quando a
 * decisão D1 for tomada (BSP ou Meta Cloud API direta).
 *
 * Canais são registrados por nome. `sendVia` tenta na ordem informada e
 * para no primeiro sucesso — é o fallback do Botão de Pânico: tenta
 * WhatsApp, cai para e-mail se o membro não tiver telefone válido.
 */
class NotificationDispatcher
{
    /** @var array<string, NotificationChannel> */
    private array $channels = [];

    public function __construct(MailChannel $mail, WhatsAppChannel $whatsapp)
    {
        $this->register($mail);

        // O WhatsApp é registrado sempre, mas `isAvailable()` devolve false
        // enquanto o .env não tiver as credenciais da Meta. Nesse estado o
        // dispatcher simplesmente cai para o e-mail — sem alteração de
        // código quando a aprovação sair.
        $this->register($whatsapp);
    }

    public function register(NotificationChannel $channel): void
    {
        $this->channels[$channel->name()] = $channel;
    }

    public function channel(string $name): ?NotificationChannel
    {
        return $this->channels[$name] ?? null;
    }

    /** Nomes dos canais realmente utilizáveis agora. */
    public function availableChannels(): array
    {
        return array_keys(array_filter(
            $this->channels,
            fn (NotificationChannel $c) => $c->isAvailable()
        ));
    }

    /**
     * Envia por um canal específico.
     */
    public function send(string $channelName, string $to, NotificationMessage $message): NotificationResult
    {
        $channel = $this->channel($channelName);

        if (!$channel) {
            return NotificationResult::failure($channelName, $to, "Canal '{$channelName}' não registrado.");
        }

        if (!$channel->isAvailable()) {
            return NotificationResult::failure($channelName, $to, "Canal '{$channelName}' indisponível.");
        }

        return $channel->send($to, $message);
    }

    /**
     * Tenta os canais na ordem e para no primeiro sucesso.
     *
     * @param  array<string, string>  $destinations  ['whatsapp' => '+5551...', 'mail' => 'x@y.com']
     * @param  array<int, string>     $order         ordem de preferência
     */
    public function sendVia(array $destinations, array $order, NotificationMessage $message): NotificationResult
    {
        $lastFailure = null;

        foreach ($order as $channelName) {
            $to = $destinations[$channelName] ?? null;

            if (!$to) {
                continue;
            }

            $result = $this->send($channelName, $to, $message);

            if ($result->success) {
                return $result;
            }

            $lastFailure = $result;
        }

        return $lastFailure
            ?? NotificationResult::failure('none', '', 'Nenhum canal com destino válido para este destinatário.');
    }

    /**
     * Disparo em massa — a forma do Botão de Pânico (T1-R07).
     *
     * Um destinatário com erro NÃO interrompe os demais: cada resultado é
     * devolvido em separado, e é isso que permite ao painel mostrar quem
     * recebeu e quem não recebeu.
     *
     * @param  array<int, array<string, string>>  $recipients
     * @return array<int, NotificationResult>
     */
    public function broadcast(array $recipients, array $order, NotificationMessage $message): array
    {
        $results = [];

        foreach ($recipients as $destinations) {
            try {
                $results[] = $this->sendVia($destinations, $order, $message);
            } catch (\Throwable $e) {
                // Cinto e suspensório: os canais já não lançam exceção, mas
                // num disparo de emergência nada pode derrubar o laço.
                Log::error('NotificationDispatcher: exceção inesperada no broadcast', [
                    'error' => $e->getMessage(),
                ]);

                $results[] = NotificationResult::failure('unknown', '', $e->getMessage());
            }
        }

        return $results;
    }
}
