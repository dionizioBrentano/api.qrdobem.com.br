<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sponsorship — apadrinhamento digital de um beneficiário.
 * Fase 6, T2-R06 do PLANO_TRILHAS_2026-08.md.
 *
 * Liga um doador recorrente a um beneficiário específico. Diferente da
 * doação à causa, que é distribuída pela OSCIP: aqui o padrinho acompanha
 * uma pessoa, e é isso que dá sentido ao vínculo.
 *
 * `unique(beneficiary_id, sponsor_tenant_id)` impede o mesmo padrinho
 * duplicar o apadrinhamento — o que geraria duas cobranças recorrentes
 * para a mesma relação.
 *
 * O apadrinhamento reaproveita `donation_subscriptions` para a cobrança. É
 * o mesmo Preapproval do Mercado Pago; o que muda é o destino declarado.
 */
class Sponsorship extends Model
{
    protected $fillable = [
        'beneficiary_id',
        'sponsor_tenant_id',
        'subscription_id',
        'monthly_amount',
        'status',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'monthly_amount' => 'decimal:2',
        'started_at'     => 'datetime',
        'ended_at'       => 'datetime',
    ];

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'sponsor_tenant_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(DonationSubscription::class, 'subscription_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
