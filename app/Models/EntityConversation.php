<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EntityConversation extends Model
{
    protected $fillable = [
        'entity_id',
        'recovery_code_hash',
        'benefactor_nickname',
        'resolved_at',
    ];

    protected $casts = [
        'benefactor_nickname' => 'encrypted',
        'resolved_at' => 'datetime',
    ];

    protected $hidden = [
        'recovery_code_hash',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(EntityMessage::class, 'conversation_id');
    }
}
