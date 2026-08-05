<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CauseProfile — vitrine pública de uma causa. Fase 3, T2-R04 e T2-R05.
 *
 * `raised_amount` é denormalizado de propósito: a vitrine é pública e
 * somaria a tabela de doações a cada visita. Atualizado quando a doação é
 * confirmada (Fase 4).
 */
class CauseProfile extends Model
{
    protected $fillable = [
        'space_id',
        'headline',
        'story',
        'category',
        'city',
        'state',
        'goal_amount',
        'raised_amount',
        'accountability',
        'is_published',
    ];

    protected $casts = [
        'goal_amount'   => 'decimal:2',
        'raised_amount' => 'decimal:2',
        'is_published'  => 'boolean',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /**
     * Percentual da meta. Null quando não há meta declarada — e isso é
     * legítimo: nem toda causa trabalha com meta fechada.
     *
     * Limitado a 100 na exibição para a barra de progresso não estourar,
     * embora `raised_amount` possa passar da meta.
     */
    public function progressPercent(): ?int
    {
        if (!$this->goal_amount || (float) $this->goal_amount <= 0) {
            return null;
        }

        $percent = ((float) $this->raised_amount / (float) $this->goal_amount) * 100;

        return (int) min(100, round($percent));
    }
}
