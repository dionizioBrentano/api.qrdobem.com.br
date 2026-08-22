<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityReferencePoint extends Model
{
    protected $fillable = [
        'entity_id',
        'name',
        'latitude',
        'longitude',
        'radius_meters',
        'days_of_week',
        'start_time',
        'end_time',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'radius_meters' => 'integer',
        'days_of_week' => 'array',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
