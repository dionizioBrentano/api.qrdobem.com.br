<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BeneficiaryNeed — o que o beneficiário pediu. Fase 4, T4-R05.
 *
 * O pedido nasce pela URL única do beneficiário. É o primeiro elo da
 * cadeia que a trilha exige: pedido → aprovação → envio → contraprova.
 */
class BeneficiaryNeed extends Model
{
    public const KIND_PRODUCT = 'product';
    public const KIND_SERVICE = 'service';
    public const KIND_MONEY   = 'money';

    public const KINDS = [
        self::KIND_PRODUCT,
        self::KIND_SERVICE,
        self::KIND_MONEY,
    ];

    protected $fillable = [
        'beneficiary_id',
        'cause_product_id',
        'title',
        'description',
        'kind',
        'estimated_amount',
        'quantity',
        'accepts_substitute',
        'period_starts_on',
        'period_ends_on',
        'status',
        'priority',
    ];

    protected $casts = [
        'estimated_amount'   => 'decimal:2',
        'quantity'           => 'decimal:2',
        'accepts_substitute' => 'boolean',
        'period_starts_on'   => 'date',
        'period_ends_on'     => 'date',
        'priority'           => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(CauseProduct::class, 'cause_product_id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(Disbursement::class, 'need_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
