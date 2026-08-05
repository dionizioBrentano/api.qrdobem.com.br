<?php

namespace App\Services\Notification;

/**
 * NotificationResult — resultado de UM envio para UM destinatário.
 * Fundação F4 do PLANO_TRILHAS_2026-08.md.
 *
 * É objeto, e não exceção, de propósito: no Botão de Pânico (T1-R07) o
 * disparo vai para vários membros de uma vez, e um telefone inválido não
 * pode impedir o alerta de chegar aos demais. Cada resultado é registrado
 * por destinatário — é o que permite ao painel mostrar quem recebeu.
 */
class NotificationResult
{
    private function __construct(
        public readonly bool $success,
        public readonly string $channel,
        public readonly string $to,
        public readonly ?string $providerId = null,
        public readonly ?string $error = null,
    ) {
    }

    public static function success(string $channel, string $to, ?string $providerId = null): self
    {
        return new self(true, $channel, $to, $providerId, null);
    }

    public static function failure(string $channel, string $to, string $error): self
    {
        return new self(false, $channel, $to, null, $error);
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'channel' => $this->channel,
            'to' => $this->to,
            'provider_id' => $this->providerId,
            'error' => $this->error,
        ];
    }
}
