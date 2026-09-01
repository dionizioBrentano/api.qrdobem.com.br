<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CauseProduct extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'space_id',
        'name',
        'purpose',
        'unit',
        'unit_price',
        'platform_fee_pct',
        'shipping_cost',
        'other_costs',
        'barcode',
        'manufacturer',
        'distributor',
        'formula_keys',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'platform_fee_pct' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'other_costs' => 'decimal:2',
        'formula_keys' => 'array',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(CauseProductAttribute::class, 'product_id');
    }

    public function substitutes(): HasMany
    {
        return $this->hasMany(CauseProductSubstitute::class, 'product_id')->orderBy('sort_order');
    }

    public function substitutedBy(): HasMany
    {
        return $this->hasMany(CauseProductSubstitute::class, 'substitute_id');
    }
}
