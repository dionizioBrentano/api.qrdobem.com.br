<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EntityReferencePoint extends Model
{
    protected $fillable = [
        'entity_id',
        'routine_id',
        'name',
        'address',
        'latitude',
        'longitude',
        'radius_meters',
        'order_index',
        'days_of_week',
        'start_time',
        'end_time',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'radius_meters' => 'integer',
        'order_index' => 'integer',
        'days_of_week' => 'array',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }

    public function windows(): HasMany
    {
        return $this->hasMany(RoutineWindow::class);
    }
}
