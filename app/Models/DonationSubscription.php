<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DonationSubscription — doação recorrente. Fase 4, T4-R04.
 *
 * Espelha o Preapproval do Mercado Pago. O cancelamento é registrado com
 * data em vez de apagar a linha: doador que cancela e volta precisa ter o
 * histórico, e a causa precisa saber que houve interrupção.
 */
class DonationSubscription extends Model
{
    protected $fillable = [
        'cause_space_id',
        'donor_tenant_id',
        'amount',
        'frequency',
        'mp_preapproval_id',
        'status',
        'next_charge_at',
        'cancelled_at',
    ];

    protected $casts = [
        'amount'         => 'decimal:2',
        'next_charge_at' => 'datetime',
        'cancelled_at'   => 'datetime',
    ];

    public function cause(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'cause_space_id');
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'donor_tenant_id');
    }

    public function donations(): HasMany
    {
        return $this->hasMany(DonationCause::class, 'subscription_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
