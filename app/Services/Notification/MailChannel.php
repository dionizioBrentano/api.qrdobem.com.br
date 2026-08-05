<?php

namespace App\Services\Notification;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * MailChannel — driver de e-mail do NotificationChannel.
 * Fundação F4 do PLANO_TRILHAS_2026-08.md (entrega 0.4).
 *
 * Usa o SMTP já configurado no servidor (mail.qrdobem.com.br:465, SSL).
 * Não substitui os Mailables existentes (VerificationCodeMail etc.) — eles
 * continuam como estão. Este canal é para notificação genérica disparada
 * pelo dispatcher, com destaque para o Botão de Pânico, onde o e-mail é o
 * fallback de quem não tem WhatsApp cadastrado.
 *
 * Nunca lança exceção: devolve NotificationResult::failure. Num disparo de
 * pânico para 8 pessoas, a exceção de uma abortaria as outras 7.
 */
class MailChannel implements NotificationChannel
{
    public function name(): string
    {
        return 'mail';
    }

    /**
     * Disponível quando há remetente configurado. Sem MAIL_FROM_ADDRESS o
     * Laravel monta a mensagem e falha no envio — melhor detectar antes.
     */
    public function isAvailable(): bool
    {
        return !empty(config('mail.from.address'));
    }

    public function send(string $to, NotificationMessage $message): NotificationResult
    {
        if (!$this->isAvailable()) {
            return NotificationResult::failure($this->name(), $to, 'Remetente de e-mail não configurado.');
        }

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return NotificationResult::failure($this->name(), $to, 'Endereço de e-mail inválido.');
        }

        try {
            // Texto puro de propósito: mensagem de emergência precisa
            // chegar e ser lida, inclusive em cliente que bloqueia HTML.
            Mail::raw($message->bodyWithUrl(), function ($mail) use ($to, $message) {
                $mail->to($to)->subject($message->subject);
            });

            return NotificationResult::success($this->name(), $to);
        } catch (\Throwable $e) {
            Log::error('MailChannel: falha no envio', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return NotificationResult::failure($this->name(), $to, $e->getMessage());
        }
    }
}
