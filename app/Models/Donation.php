<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Donation — uma doação. Fase 4, T4-R01 a T4-R04.
 *
 * `mp_payment_id` é UNIQUE na tabela: o Mercado Pago reenvia o webhook, e
 * sem essa restrição a mesma doação seria creditada duas vezes na causa.
 */
class Donation extends Model
{
    public const METHOD_PIX          = 'pix';
    public const METHOD_CREDIT_CARD  = 'credit_card';
    public const METHOD_CITIZEN_CARD = 'citizen_card';

    protected $fillable = [
        'cause_space_id',
        'donor_tenant_id',
        'donor_name',
        'donor_email',
        'amount',
        'payment_method',
        'status',
        'mp_payment_id',
        'mp_preference_id',
        'mp_status',
        'subscription_id',
        'is_anonymous',
        'message',
        'paid_at',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'is_anonymous' => 'boolean',
        'paid_at'      => 'datetime',
    ];

    /**
     * E-mail do doador nunca sai em listagem pública. A vitrine mostra
     * quem doou (quando não é anônimo) e quanto — nunca o contato, que
     * viraria lista de captação para terceiros.
     */
    protected $hidden = [
        'donor_email',
    ];

    public function cause(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'cause_space_id');
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'donor_tenant_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(DonationSubscription::class, 'subscription_id');
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /** Nome para exibição pública, respeitando o anonimato. */
    public function publicName(): string
    {
        if ($this->is_anonymous) {
            return 'Doador anônimo';
        }

        return $this->donor_name ?: ($this->donor?->nickname ?: 'Doador');
    }
}
