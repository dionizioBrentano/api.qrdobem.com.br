<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MedicationLeaflet — bula em cache. Fase 6, T1-R10.
 *
 * A bula é buscada uma vez e guardada aqui. Não depender da fonte externa
 * em tempo de leitura é o que permite ao usuário abrir a prescrição sem
 * internet — e num módulo de medicação, isso importa.
 *
 * `posology_excerpt` guarda só o trecho da posologia. O texto inteiro fica
 * em `content` para consulta, mas o que alimenta a sugestão de horários é
 * o trecho — e ele é sempre exibido ao usuário junto com a sugestão, para
 * que ele confira. O cruzamento é ASSISTIVO, nunca prescritivo.
 */
class MedicationLeaflet extends Model
{
    protected $fillable = [
        'medication_id',
        'source_url',
        'content',
        'posology_excerpt',
        'fetched_at',
    ];

    protected $casts = [
        'fetched_at' => 'datetime',
    ];

    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }

    /**
     * Tenta extrair o intervalo em horas do texto da posologia.
     *
     * Reconhece as formas mais comuns em bula brasileira:
     *   "de 8 em 8 horas", "a cada 8 horas", "8/8h", "duas vezes ao dia"
     *
     * Devolve null quando não reconhece — e null aqui é resposta legítima,
     * não falha. O sistema então pede o intervalo ao usuário em vez de
     * chutar. Chutar posologia é o único erro inaceitável deste módulo.
     */
    public function guessIntervalHours(): ?int
    {
        $text = mb_strtolower((string) ($this->posology_excerpt ?: $this->content));

        if ($text === '') {
            return null;
        }

        // "de 8 em 8 horas" / "a cada 8 horas" / "8/8h"
        if (preg_match('/(?:de\s*)?(\d{1,2})\s*(?:em|\/)\s*\1\s*(?:h|horas)/u', $text, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/a\s*cada\s*(\d{1,2})\s*(?:h|horas)/u', $text, $m)) {
            return (int) $m[1];
        }

        // "duas vezes ao dia" e variantes por extenso
        $timesPerDay = [
            'uma vez ao dia'    => 24,
            '1 vez ao dia'      => 24,
            'duas vezes ao dia' => 12,
            '2 vezes ao dia'    => 12,
            'tres vezes ao dia' => 8,
            'três vezes ao dia' => 8,
            '3 vezes ao dia'    => 8,
            'quatro vezes ao dia' => 6,
            '4 vezes ao dia'    => 6,
        ];

        foreach ($timesPerDay as $needle => $hours) {
            if (str_contains($text, $needle)) {
                return $hours;
            }
        }

        return null;
    }
}
