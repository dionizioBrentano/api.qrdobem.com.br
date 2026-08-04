<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityHealthField extends Model
{
    /**
     * Lista fechada de campos de saúde. Nenhuma chave fora desta lista é aceita.
     */
    public const FIELD_KEYS = [
        'blood_type',
        'allergies',
        'chronic_conditions',
        'continuous_medications',
        'relevant_surgeries',
        'substance_use_risk',
        'caregiver_name',
        'caregiver_contact',
    ];

    /**
     * Nunca podem ser públicos. Só aparecem sob emergência declarada.
     */
    public const ALWAYS_RESTRICTED = [
        'continuous_medications',
        'substance_use_risk',
    ];

    /**
     * Nunca aparece na visualização pública normal, mesmo marcado como público
     * pelo tutor — mesma lógica de "nunca expor contato direto" do resto do sistema.
     */
    public const NEVER_PUBLIC_IN_NORMAL_VIEW = 'caregiver_contact';

    protected $fillable = [
        'entity_id',
        'field_key',
        'field_value',
        'is_public',
    ];

    protected $casts = [
        'field_value' => 'encrypted',
        'is_public' => 'boolean',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
