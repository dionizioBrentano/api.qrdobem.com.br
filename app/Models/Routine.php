<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Routine extends Model
{
    protected $fillable = [
        'entity_id',
        'space_id',
        'name',
        'is_active',
        'skip_alert_inside_trail',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'skip_alert_inside_trail' => 'boolean',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function points(): HasMany
    {
        return $this->hasMany(EntityReferencePoint::class)->orderBy('order_index');
    }

    public function windows(): HasMany
    {
        return $this->hasMany(RoutineWindow::class);
    }
}
