<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantTermAcceptance extends Model
{
    protected $fillable = [
        'tenant_id',
        'entity_id',
        'term_type',
        'term_version',
        'ip_address',
        'user_agent',
        'accepted_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function entity()
    {
        return $this->belongsTo(Entity::class);
    }
}
