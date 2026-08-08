<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DonationCause — doação FINANCEIRA a uma causa (checkout). Fase 4.
 *
 * Bounded context separado do legado de comprovantes (DonationReceipt). Aqui
 * é o produto atual: pagamento via Mercado Pago (Checkout Pro), taxa de 12%
 * da OSCIP sobre o bruto, doador convidado (guest) e recibo por e-mail.
 *
 * Tabela `donation_causes` (antes chamada `donations`, renomeada para não
 * colidir com o legado de recibos que ocupa `donations` em produção).
 *
 * `mp_payment_id` é UNIQUE na tabela: o Mercado Pago reenvia o webhook, e
 * sem essa restrição a mesma doação seria creditada duas vezes na causa.
 */
class DonationCause extends Model
{
    protected $table = 'donation_causes';

    public const METHOD_PIX          = 'pix';
    public const METHOD_CREDIT_CARD  = 'credit_card';
    public const METHOD_CITIZEN_CARD = 'citizen_card';

    protected $fillable = [
        'public_token',
        'cause_space_id',
        'donor_tenant_id',
        'donor_name',
        'donor_email',
        'donor_document_encrypted',
        'donor_document_hash',
        'lgpd_consent_at',
        'amount',
        'amount_gross',
        'platform_fee_percent',
        'platform_fee_amount',
        'payment_fee_amount',
        'amount_to_cause',
        'cover_fees',
        'extra_platform_support',
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
        'amount'                 => 'decimal:2',
        'amount_gross'           => 'decimal:2',
        'platform_fee_percent'   => 'decimal:2',
        'platform_fee_amount'    => 'decimal:2',
        'payment_fee_amount'     => 'decimal:2',
        'amount_to_cause'        => 'decimal:2',
        'extra_platform_support' => 'decimal:2',
        'cover_fees'               => 'boolean',
        'is_anonymous'             => 'boolean',
        'paid_at'                  => 'datetime',
        'lgpd_consent_at'          => 'datetime',
        // CPF do doador convidado cifrado em repouso. O cast `encrypted` não
        // é determinístico: por isso a busca usa o blind index, nunca esta
        // coluna. Mesmo padrão de Person::$casts.
        'donor_document_encrypted' => 'encrypted',
    ];

    /**
     * Dados que nunca saem em resposta pública. O e-mail viraria lista de
     * captação para terceiros; o CPF (cifrado) e seu blind index não têm por
     * que trafegar — a vitrine mostra quem doou e quanto, jamais o contato
     * ou o documento.
     */
    protected $hidden = [
        'donor_email',
        'donor_document_encrypted',
        'donor_document_hash',
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
