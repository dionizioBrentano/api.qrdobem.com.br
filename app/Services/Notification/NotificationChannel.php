<?php

namespace App\Services\Notification;

/**
 * NotificationChannel — contrato de canal de notificação.
 * Fundação F4 do PLANO_TRILHAS_2026-08.md (entrega 0.4).
 *
 * POR QUE ISTO EXISTE
 * O Botão de Pânico (T1-R07) precisa disparar alerta simultâneo por
 * WhatsApp para todos os membros da família. Se cada controller chamar a
 * API do WhatsApp direto, trocar de provedor (decisão D1: BSP ou Meta
 * Cloud API) vira reescrita espalhada. Nada no sistema chama canal
 * concreto: tudo passa pelo NotificationDispatcher.
 *
 * Drivers previstos: mail (existe), whatsapp (Fase 2), sms, push.
 */
interface NotificationChannel
{
    /**
     * Identificador curto do canal: 'mail', 'whatsapp', 'sms', 'push'.
     */
    public function name(): string;

    /**
     * O canal está utilizável agora? Falso quando falta credencial ou
     * configuração — checado antes do envio para não estourar exceção em
     * disparo de massa.
     */
    public function isAvailable(): bool;

    /**
     * Envia uma mensagem.
     *
     * @param  string  $to       Destino no formato do canal (e-mail, telefone E.164, token).
     * @param  NotificationMessage  $message
     * @return NotificationResult  Nunca lança exceção: um destinatário com
     *                             erro não pode abortar o disparo dos outros.
     */
    public function send(string $to, NotificationMessage $message): NotificationResult;
}
