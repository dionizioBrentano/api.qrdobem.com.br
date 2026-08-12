<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityEmergencyDeclaration extends Model
{
    /**
     * Janela de validade de uma emergência declarada, em horas.
     *
     * VALOR ASSUMIDO: não havia critério definido no projeto. 24h é o padrão
     * adotado nesta sprint e pode precisar de ajuste de produto.
     */
    public const ACTIVE_WINDOW_HOURS = 24;

    protected $fillable = [
        'entity_id',
        'declarant_cpf_encrypted',
        'declared_at',
        'latitude',
        'longitude',
        'location_accuracy',
        'note',
        'ip',
        'user_agent',
        'status',
        'resolved_at',
    ];

    protected $casts = [
        'declarant_cpf_encrypted' => 'encrypted',
        'declared_at' => 'datetime',
        'resolved_at' => 'datetime',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];

    protected $hidden = [
        'declarant_cpf_encrypted',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function mapsUrl(): ?string
    {
        if ($this->latitude && $this->longitude) {
            return "https://www.google.com/maps/search/?api=1&query={$this->latitude},{$this->longitude}";
        }
        return null;
    }
}
