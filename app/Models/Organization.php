<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'document',
        'owner_tenant_id',
    ];

    public function owner()
    {
        return $this->belongsTo(Tenant::class, 'owner_tenant_id');
    }

    public function users()
    {
        return $this->belongsToMany(Tenant::class, 'organization_user')
                    ->withPivot('role')
                    ->withTimestamps();
    }

    public function entities(): HasMany
    {
        return $this->hasMany(Entity::class);
    }
}
