<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EntityMessage extends Model
{
    protected $fillable = [
        'entity_id',
        'sender_name',
        'sender_contact',
        'message',
        'latitude',
        'longitude',
        'read_at',
    ];

    protected $casts = [
        'sender_name' => 'encrypted',
        'sender_contact' => 'encrypted',
        'message' => 'encrypted',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'read_at' => 'datetime',
    ];

    public function entity()
    {
        return $this->belongsTo(Entity::class);
    }
}
