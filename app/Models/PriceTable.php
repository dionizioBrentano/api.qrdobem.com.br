<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PriceTable extends Model
{
    use HasFactory;

    protected $fillable = [
        'target_type',
        'target_id',
        'unit_price',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
    ];
}
