<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityObjectField extends Model
{
    /** Lista fechada dos 5 avisos de manuseio. */
    public const HANDLING_FLAGS = [
        'handling_fragile',
        'handling_light_sensitive',
        'handling_keep_refrigerated',
        'handling_do_not_invert',
        'handling_sentimental_value',
    ];

    public const PUBLIC_LABEL_MAX = 200;

    protected $fillable = [
        'entity_id',
        'description',
        'description_is_public',
        'public_label',
        'handling_fragile',
        'handling_light_sensitive',
        'handling_keep_refrigerated',
        'handling_do_not_invert',
        'handling_sentimental_value',
        'handling_notes_extra',
    ];

    protected $casts = [
        'description' => 'encrypted',
        'description_is_public' => 'boolean',
        'handling_fragile' => 'boolean',
        'handling_light_sensitive' => 'boolean',
        'handling_keep_refrigerated' => 'boolean',
        'handling_do_not_invert' => 'boolean',
        'handling_sentimental_value' => 'boolean',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
