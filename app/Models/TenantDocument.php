<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantDocument extends Model
{
    protected $fillable = [
        'tenant_id',
        'document_type',
        'document_number',
        'document_country',
        'is_primary',
        'verified_at',
    ];

    protected $casts = [
        'document_number' => 'encrypted',
        'is_primary' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
