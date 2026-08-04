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
    ];

    protected $casts = [
        'declarant_cpf_encrypted' => 'encrypted',
        'declared_at' => 'datetime',
    ];

    protected $hidden = [
        'declarant_cpf_encrypted',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
