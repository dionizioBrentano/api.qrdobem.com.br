<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CreditPricing extends Model
{
    use HasFactory;

    protected $table = 'credit_pricing';

    protected $fillable = [
        'unit_price',
        'min_quantity',
        'max_quantity',
        'adventure_yearly_price',
        'family_pack_qty',
        'family_pack_price',
        'launch_offer_enabled',
        'launch_offer_discount_percent',
        'launch_offer_ends_at',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'min_quantity' => 'integer',
        'max_quantity' => 'integer',
        'adventure_yearly_price' => 'decimal:2',
        'family_pack_qty' => 'integer',
        'family_pack_price' => 'decimal:2',
        'launch_offer_enabled' => 'boolean',
        'launch_offer_discount_percent' => 'decimal:2',
        'launch_offer_ends_at' => 'date',
    ];
}
