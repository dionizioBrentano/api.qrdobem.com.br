<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutineWindow extends Model
{
    protected $fillable = [
        'routine_id',
        'entity_reference_point_id',
        'day_of_week',
        'start_time',
        'end_time',
        'tolerance_minutes',
        'expects_movement',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'tolerance_minutes' => 'integer',
        'expects_movement' => 'boolean',
    ];

    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }

    public function point(): BelongsTo
    {
        return $this->belongsTo(EntityReferencePoint::class, 'entity_reference_point_id');
    }
}
