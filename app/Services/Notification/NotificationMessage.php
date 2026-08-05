<?php

namespace App\Services\Notification;

/**
 * NotificationMessage — o conteúdo a enviar, independente de canal.
 * Fundação F4 do PLANO_TRILHAS_2026-08.md.
 *
 * `template` e `templateData` existem por causa do WhatsApp: mensagem
 * iniciada pelo sistema fora da janela de 24 horas SÓ trafega por template
 * pré-aprovado pela Meta. O canal de e-mail ignora esses campos e usa
 * `subject` + `body`; o de WhatsApp usa o template. O chamador escreve uma
 * vez e serve os dois.
 */
class NotificationMessage
{
    public function __construct(
        public readonly string $subject,
        public readonly string $body,
        /** Nome do template aprovado (WhatsApp). Null usa corpo livre. */
        public readonly ?string $template = null,
        /** Variáveis do template, na ordem esperada pelo provedor. */
        public readonly array $templateData = [],
        /** Link levado na mensagem — ex.: página da emergência. */
        public readonly ?string $url = null,
        /** 'normal' | 'urgent'. Pânico é urgent: fura fila. */
        public readonly string $priority = 'normal',
    ) {
    }

    public function isUrgent(): bool
    {
        return $this->priority === 'urgent';
    }

    /** Corpo com o link anexado, para canais sem campo próprio de URL. */
    public function bodyWithUrl(): string
    {
        return $this->url ? rtrim($this->body) . "\n\n" . $this->url : $this->body;
    }
}
