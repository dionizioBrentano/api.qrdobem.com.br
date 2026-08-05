<?php

namespace App\Services;

use App\Models\Prescription;

/**
 * IcsCalendarService — exportação de horários para a agenda do celular.
 * Fase 6, T1-R11 do PLANO_TRILHAS_2026-08.md.
 *
 * O requisito fala em "sincronizar o calendário com as agendas nativas de
 * Android e iOS". O `.ics` (RFC 5545) é o formato que os dois abrem
 * nativamente: baixar o arquivo já oferece "adicionar à agenda", sem app
 * intermediário, sem SDK, sem permissão especial.
 *
 * Sem dependência externa: o formato é texto, e trazer biblioteca para
 * gerar meia dúzia de linhas seria mais risco de deploy que benefício —
 * `composer require` em CPanel é ponto recorrente de falha.
 *
 * LIMITE HONESTO: `.ics` é exportação, não sincronização de mão dupla.
 * Alterar o horário aqui não muda o evento já criado no celular do usuário
 * — ele precisa reimportar. Sincronização real exigiria integração com
 * Google Calendar API e Apple EventKit, que é a versão definitiva.
 */
class IcsCalendarService
{
    /**
     * Gera o .ics de uma prescrição.
     * Um VEVENT por horário do dia, com repetição diária.
     */
    public function forPrescription(Prescription $prescription): string
    {
        $times = $prescription->schedule_times ?: $prescription->calculateSchedule();

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//QR do Bem//Medicacao//PT-BR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];

        $start = $prescription->starts_on ?: now();

        foreach ($times as $index => $time) {
            [$hour, $minute] = array_pad(explode(':', $time), 2, '00');

            $dtStart = $start->copy()->setTime((int) $hour, (int) $minute);

            // UID estável: reimportar não duplica o evento na agenda, ele
            // substitui o anterior. Sem isso, cada exportação viraria uma
            // fileira nova de alarmes no celular do usuário.
            $uid = "presc-{$prescription->id}-{$index}@qrdobem.com.br";

            $summary = $this->escape(
                'Medicação: ' . $prescription->medication_name
                . ($prescription->dosage ? ' (' . $prescription->dosage . ')' : '')
            );

            $description = $this->escape($prescription->notes ?: 'Lembrete do QR do Bem.');

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $uid;
            $lines[] = 'DTSTAMP:' . now()->utc()->format('Ymd\THis\Z');
            $lines[] = 'DTSTART:' . $dtStart->format('Ymd\THis');
            $lines[] = 'DURATION:PT15M';
            $lines[] = 'SUMMARY:' . $summary;
            $lines[] = 'DESCRIPTION:' . $description;

            // Repetição diária. Com data de término, a regra respeita o fim
            // do tratamento — alarme de remédio que já acabou é ruído que
            // faz o usuário desativar tudo.
            $lines[] = $prescription->ends_on
                ? 'RRULE:FREQ=DAILY;UNTIL=' . $prescription->ends_on->copy()->endOfDay()->format('Ymd\THis')
                : 'RRULE:FREQ=DAILY';

            // Alarme no horário exato.
            $lines[] = 'BEGIN:VALARM';
            $lines[] = 'TRIGGER:PT0M';
            $lines[] = 'ACTION:DISPLAY';
            $lines[] = 'DESCRIPTION:' . $summary;
            $lines[] = 'END:VALARM';

            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        // CRLF é exigência da RFC 5545. Com \n puro, parte dos clientes
        // recusa o arquivo sem dizer por quê.
        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Escapa os caracteres reservados do formato.
     * Vírgula e ponto e vírgula não escapados quebram o parse e o evento
     * aparece truncado na agenda.
     */
    private function escape(string $text): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n"],
            ['\\\\', '\\;', '\\,', '\\n', '\\n'],
            $text
        );
    }
}
