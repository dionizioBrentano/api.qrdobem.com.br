<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Entity extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'credit_batch_id',
        'unique_code',
        'type',
        'encrypted_name',
        'encrypted_contact_phone',
        'encrypted_contact_email',
        'encrypted_medical_info',
        'encrypted_additional_info',
        'is_active',
        // Sem isso o Eloquent descarta o status e toda entidade nasce
        // como 'pending_term', mesmo com o termo aceito.
        'status',
    ];

    protected $casts = [
        'encrypted_name' => 'encrypted',
        'encrypted_contact_phone' => 'encrypted',
        'encrypted_contact_email' => 'encrypted',
        'encrypted_medical_info' => 'encrypted',
        'encrypted_additional_info' => 'encrypted',
        'is_active' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function customAttributes(): MorphMany
    {
        return $this->morphMany(CustomAttribute::class, 'owner');
    }
}
