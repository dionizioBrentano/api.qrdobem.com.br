<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityPetField extends Model
{
    /** Lista fechada de espécies. */
    public const SPECIES = ['dog', 'cat', 'horse', 'bird', 'rabbit', 'reptile', 'other'];

    /** Lista fechada de portes, relativa dentro da própria espécie. */
    public const SIZES = ['small', 'medium', 'large'];

    /** Três estados: "não sei" é resposta legítima, diferente de "não". */
    public const NEUTERED_STATES = ['yes', 'no', 'unknown'];

    protected $fillable = [
        'entity_id',
        'species',
        'species_other_description',
        'size',
        'size_is_public',
        'color',
        'color_is_public',
        'is_neutered',
        'is_neutered_is_public',
        'physical_description',
        'physical_description_is_public',
        'clinical_notes',
        'clinical_notes_is_public',
        'reference_contact',
        'reference_contact_is_public',
        'vaccinations_is_public',
    ];

    protected $casts = [
        'color' => 'encrypted',
        'physical_description' => 'encrypted',
        'clinical_notes' => 'encrypted',
        'reference_contact' => 'encrypted',
        'size_is_public' => 'boolean',
        'color_is_public' => 'boolean',
        'is_neutered_is_public' => 'boolean',
        'physical_description_is_public' => 'boolean',
        'clinical_notes_is_public' => 'boolean',
        'reference_contact_is_public' => 'boolean',
        'vaccinations_is_public' => 'boolean',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
