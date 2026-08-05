<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PanicEvent — um acionamento do Botão de Pânico.
 * Fase 2 antecipada por decisão do proprietário (06/08/2026), T1-R07.
 *
 * O acionamento é registrado ANTES de qualquer tentativa de envio. Se a
 * notificação falhar inteira, ainda existe o registro de que alguém pediu
 * socorro, com hora e local — que é a informação que importa depois.
 */
class PanicEvent extends Model
{
    public const SOURCE_APP    = 'app';
    public const SOURCE_QR     = 'qr';
    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'space_id',
        'entity_id',
        'triggered_by_tenant_id',
        'source',
        'status',
        'latitude',
        'longitude',
        'location_accuracy',
        'note',
        'triggered_at',
        'resolved_at',
    ];

    protected $casts = [
        'triggered_at' => 'datetime',
        'resolved_at'  => 'datetime',
        'latitude'     => 'decimal:7',
        'longitude'    => 'decimal:7',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(PanicRecipient::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /** Link do Google Maps, quando houve coordenada. */
    public function mapsUrl(): ?string
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }

        return "https://www.google.com/maps?q={$this->latitude},{$this->longitude}";
    }
}
