<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * QrPrintBatch — lote de QR Codes para geração e impressão.
 * Fase 3, T2-R03 do PLANO_TRILHAS_2026-08.md.
 *
 * Serve a causa que precisa de 200 etiquetas para uma campanha e não vai
 * cadastrar uma por uma. Os códigos ficam em JSON porque são sempre lidos
 * juntos, na hora de imprimir a folha.
 */
class QrPrintBatch extends Model
{
    /** Teto por lote. Acima disso, a geração não cabe numa execução de cron. */
    public const MAX_QUANTITY = 500;

    protected $fillable = [
        'space_id',
        'created_by_tenant_id',
        'label',
        'quantity',
        'codes',
        'status',
        'error',
        'generated_at',
    ];

    protected $casts = [
        'codes'        => 'array',
        'quantity'     => 'integer',
        'generated_at' => 'datetime',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'created_by_tenant_id');
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }
}
