<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MedicationConfirmation — o voto de um usuário sobre um medicamento.
 * Fase 6, T1-R09 do PLANO_TRILHAS_2026-08.md.
 *
 * `unique(medication_id, tenant_id)` no banco: um voto por pessoa. É o que
 * faz "3 confirmações" significar três PESSOAS distintas, e não a mesma
 * clicando três vezes.
 */
class MedicationConfirmation extends Model
{
    public const ACTION_CONFIRMED = 'confirmed';
    public const ACTION_CORRECTED = 'corrected';

    protected $fillable = [
        'medication_id',
        'tenant_id',
        'action',
        'corrected_payload',
    ];

    protected $casts = [
        'corrected_payload' => 'array',
    ];

    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
