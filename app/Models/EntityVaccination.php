<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityVaccination extends Model
{
    protected $fillable = [
        'entity_id',
        'vaccine_name',
        'applied_at',
    ];

    protected $casts = [
        'applied_at' => 'date',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
