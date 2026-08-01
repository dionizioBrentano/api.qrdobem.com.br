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
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'min_quantity' => 'integer',
        'max_quantity' => 'integer',
    ];
}
