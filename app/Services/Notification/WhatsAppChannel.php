<?php

namespace App\Services\Notification;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsAppChannel — driver de WhatsApp do NotificationChannel.
 * Fase 2, T1-R07 do PLANO_TRILHAS_2026-08.md.
 *
 * ESCRITO CONTRA A META CLOUD API (Graph API).
 * A decisão D1 — BSP intermediário ou Cloud API direta — segue aberta, mas
 * a Cloud API é o caminho sem intermediário e sem contrato adicional. Se a
 * escolha mudar para um BSP, o que muda é a URL e o formato do payload:
 * o resto do sistema não sabe que este driver existe, porque tudo passa
 * pelo NotificationDispatcher.
 *
 * A REGRA DA JANELA DE 24 HORAS — não é detalhe de implementação
 * Mensagem iniciada pelo sistema fora de uma conversa ativa SÓ trafega por
 * *template* previamente aprovado pela Meta. O Botão de Pânico é sempre
 * mensagem iniciada pelo sistema, então **sempre** usa template. Tentar
 * mandar texto livre resultaria em erro 131047 e o alerta não sairia — o
 * pior momento possível para descobrir isso.
 *
 * Por isso: sem `template` na mensagem, este canal se recusa a enviar em
 * vez de tentar e falhar silenciosamente.
 *
 * CONFIGURAÇÃO (.env do servidor):
 *   WHATSAPP_PHONE_NUMBER_ID=...   (do painel da Meta)
 *   WHATSAPP_ACCESS_TOKEN=...      (token permanente do app)
 *   WHATSAPP_API_VERSION=v21.0     (opcional)
 *   WHATSAPP_TEMPLATE_LANGUAGE=pt_BR (opcional)
 *
 * Sem as duas primeiras, `isAvailable()` devolve false e o dispatcher cai
 * para o e-mail sozinho. É o que faz o sistema funcionar hoje, antes da
 * aprovação da Meta, sem nenhuma alteração de código depois.
 */
class WhatsAppChannel implements NotificationChannel
{
    public function name(): string
    {
        return 'whatsapp';
    }

    public function isAvailable(): bool
    {
        return !empty(config('services.whatsapp.phone_number_id'))
            && !empty(config('services.whatsapp.access_token'));
    }

    public function send(string $to, NotificationMessage $message): NotificationResult
    {
        if (!$this->isAvailable()) {
            return NotificationResult::failure($this->name(), $to, 'Canal WhatsApp não configurado.');
        }

        $phone = $this->normalizePhone($to);

        if (!$phone) {
            return NotificationResult::failure($this->name(), $to, 'Telefone inválido para WhatsApp.');
        }

        // Ver a regra da janela de 24h no cabeçalho. Sem template, não há
        // envio possível — e falhar aqui, explicitamente, é melhor que
        // receber 131047 da Meta no meio de uma emergência.
        if (!$message->template) {
            return NotificationResult::failure(
                $this->name(),
                $phone,
                'Mensagem sem template. A Meta só entrega mensagem iniciada pelo sistema por template aprovado.'
            );
        }

        $version = config('services.whatsapp.api_version', 'v21.0');
        $phoneNumberId = config('services.whatsapp.phone_number_id');
        $language = config('services.whatsapp.template_language', 'pt_BR');

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $phone,
            'type'              => 'template',
            'template'          => [
                'name'     => $message->template,
                'language' => ['code' => $language],
                'components' => [
                    [
                        'type'       => 'body',
                        // A ordem dos parâmetros precisa bater com a do
                        // template aprovado — a Meta valida só a
                        // quantidade, e um valor fora de ordem produz uma
                        // mensagem coerente e errada.
                        'parameters' => array_map(
                            fn ($value) => ['type' => 'text', 'text' => (string) $value],
                            $message->templateData
                        ),
                    ],
                ],
            ],
        ];

        try {
            $response = Http::withToken(config('services.whatsapp.access_token'))
                // Timeout curto: numa emergência, esperar 30s por um
                // destinatário atrasa os outros da fila de disparo.
                ->timeout(10)
                ->connectTimeout(5)
                ->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/messages", $payload);
        } catch (\Throwable $e) {
            Log::error('WhatsAppChannel: falha de conexão', [
                'to'    => $this->maskPhone($phone),
                'error' => $e->getMessage(),
            ]);

            return NotificationResult::failure($this->name(), $phone, $e->getMessage());
        }

        if ($response->successful()) {
            $providerId = $response->json('messages.0.id');

            return NotificationResult::success($this->name(), $phone, $providerId);
        }

        // O corpo do erro fica no log, nunca na resposta HTTP: ele traz
        // detalhes da conta da Meta que não interessam ao cliente.
        Log::error('WhatsAppChannel: envio recusado', [
            'to'          => $this->maskPhone($phone),
            'http_status' => $response->status(),
            'body'        => $response->json(),
        ]);

        return NotificationResult::failure(
            $this->name(),
            $phone,
            'WhatsApp recusou o envio (HTTP ' . $response->status() . ').'
        );
    }

    /**
     * Normaliza o telefone para o formato E.164 sem o "+", que é o que a
     * Graph API espera.
     *
     * Brasil: acrescenta o 55 quando o número vem só com DDD, que é como
     * o usuário digita no cadastro. Sem isso, todo telefone brasileiro
     * cadastrado normalmente falharia.
     */
    private function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if (strlen($digits) < 10) {
            return null;
        }

        // 10 ou 11 dígitos = número nacional com DDD, sem o país.
        if (strlen($digits) === 10 || strlen($digits) === 11) {
            $digits = '55' . $digits;
        }

        return $digits;
    }

    /** Telefone mascarado para o log: número inteiro em log é dado pessoal. */
    private function maskPhone(string $phone): string
    {
        return substr($phone, 0, 4) . '****' . substr($phone, -2);
    }
}
