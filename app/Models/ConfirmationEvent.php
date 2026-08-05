<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ConfirmationEvent — o registro de uma confirmação autenticada.
 * Fase 5, T3-R05, T3-R06 e T3-R07 do PLANO_TRILHAS_2026-08.md.
 *
 * É a prova que o cliente corporativo vai apresentar numa auditoria ou num
 * processo trabalhista: quem confirmou, o quê, quando, de onde, e se a
 * senha foi conferida.
 *
 * O evento é IMUTÁVEL na prática — não há endpoint de edição. Comprovante
 * que pode ser alterado depois não comprova nada. Correção se faz com um
 * evento novo, não sobrescrevendo o antigo.
 */
class ConfirmationEvent extends Model
{
    protected $fillable = [
        'space_id',
        'template_id',
        'entity_id',
        'actor_id',
        'payload',
        'password_verified',
        'ip_address',
        'user_agent',
        'latitude',
        'longitude',
        'confirmed_at',
    ];

    protected $casts = [
        'payload'           => 'array',
        'password_verified' => 'boolean',
        'confirmed_at'      => 'datetime',
        'latitude'          => 'decimal:7',
        'longitude'         => 'decimal:7',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ConfirmationTemplate::class, 'template_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(ConfirmationActor::class, 'actor_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
