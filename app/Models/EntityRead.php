<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityRead extends Model
{
    use HasFactory;

    protected $fillable = [
        'entity_id',
        'unique_code',
        'entity_type',
        'read_at',
        'ip_hash',
        'user_agent',
        'latitude',
        'longitude',
        'source',
        'meta',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'meta' => 'array',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
