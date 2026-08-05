<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * HeatmapCell — célula agregada do mapa de calor.
 * Fase 6, T2-R07 do PLANO_TRILHAS_2026-08.md.
 *
 * Agrega leituras de QR por região, expondo onde há maior volume de
 * ocorrências. Sem exigência de k-anonimato, por decisão do proprietário
 * em 05/08/2026.
 *
 * POR QUE CÉLULA E NÃO PONTO
 * Guardar a coordenada exata de cada leitura faria a tabela crescer sem
 * limite e não melhoraria o mapa: em zoom de cidade, mil pontos a 10
 * metros um do outro viram uma mancha só. Arredondar a 2 casas decimais dá
 * célula de ~1,1 km, que é a resolução em que o mapa é lido de fato.
 *
 * O contador é incrementado; a linha nunca é reescrita por leitura-soma-
 * gravação. Duas leituras simultâneas na mesma célula se perderiam.
 */
class HeatmapCell extends Model
{
    protected $fillable = [
        'cell_lat',
        'cell_lng',
        'entity_type',
        'reads_count',
        'last_read_at',
    ];

    protected $casts = [
        'cell_lat'     => 'decimal:2',
        'cell_lng'     => 'decimal:2',
        'reads_count'  => 'integer',
        'last_read_at' => 'datetime',
    ];

    /** Arredonda uma coordenada para a célula correspondente. */
    public static function cellOf(float $value): float
    {
        return round($value, 2);
    }

    /**
     * Registra uma leitura na célula, criando-a se necessário.
     *
     * `firstOrCreate` + `increment` em vez de ler, somar e gravar: sob duas
     * leituras simultâneas, a versão ingênua perderia uma das contagens.
     */
    public static function record(float $latitude, float $longitude, string $entityType): void
    {
        $cell = static::firstOrCreate(
            [
                'cell_lat'    => static::cellOf($latitude),
                'cell_lng'    => static::cellOf($longitude),
                'entity_type' => $entityType,
            ],
            ['reads_count' => 0]
        );

        $cell->increment('reads_count');
        $cell->forceFill(['last_read_at' => now()])->saveQuietly();
    }
}
