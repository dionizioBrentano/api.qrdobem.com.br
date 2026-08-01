<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CreditOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'quantity',
        'unit_price',
        'price_amount',
        'status',
        'mp_preference_id',
        'mp_payment_id',
        'external_reference',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'price_amount' => 'decimal:2',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
