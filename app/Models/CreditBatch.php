<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CreditBatch extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'creator_tenant_id',
        'organization_id',
        'amount_total',
        'amount_available',
        'unit_price',
        'expires_at',
        'status',
        'source',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'unit_price' => 'decimal:2',
    ];

    public function creator()
    {
        return $this->belongsTo(Tenant::class, 'creator_tenant_id');
    }



    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
