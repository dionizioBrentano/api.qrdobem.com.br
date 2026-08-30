<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'entity_id',
        'device_id',
        'label',
        'role',
        'last_seen_at',
        'token_hash',
        'token_expires_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'token_expires_at' => 'datetime',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
