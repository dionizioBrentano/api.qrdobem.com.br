<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registra eventos e ocorrências da Trilha Aventura.
 *
 * Para o type = 'wellness_check' (Checagem "Estou bem"):
 * status deve ser 'pending' -> 'ok' | 'escalated'
 *
 * Os status prévios (pending_challenge, silent_triggered, resolved, expired)
 * continuam válidos para outros tipos.
 */
class AdventureEvent extends Model
{
    protected $fillable = [
        'entity_id',
        'type',
        'status',
        'metadata',
        'reason',
        'requested_at',
        'responded_at',
        'device_id',
    ];

    protected $casts = [
        'metadata' => 'array',
        'requested_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
