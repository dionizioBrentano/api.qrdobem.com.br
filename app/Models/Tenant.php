<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'nickname',
        'email',
        'document_number',
        'firebase_uid',
        'role',
        'qr_quota',
        'is_active',
        'cpf',
        'dob',
        'phone',
        'profile_status',
        'email_verified_at',
        'address_street',
        'address_number',
        'address_complement',
        'address_neighborhood',
        'address_city',
        'address_state',
        'address_zipcode',
        'originating_conversation_id',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'dob' => 'date',
        'is_active' => 'boolean',
    ];

    public function organizations()
    {
        return $this->belongsToMany(Organization::class, 'organization_user')
                    ->withPivot('role')
                    ->withTimestamps();
    }

    public function documents()
    {
        return $this->hasMany(TenantDocument::class);
    }

    public function termAcceptances()
    {
        return $this->hasMany(TenantTermAcceptance::class);
    }
}
