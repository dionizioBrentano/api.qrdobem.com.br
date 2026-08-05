<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HealthDiaryEntry — registro do diário de saúde.
 * Fase 6, T1-R08 do PLANO_TRILHAS_2026-08.md.
 *
 * Serve pessoa e pet com a mesma tabela: sintoma, consulta, exame, dose
 * tomada, vacina, anotação. A diferença entre um humano e um animal, aqui,
 * está no perfil dono do registro — não na estrutura do que se anota.
 *
 * `measure_key` / `measure_value` guardam medição livre (pressão, glicemia,
 * peso, temperatura) como texto: "12x8", "110 mg/dL", "3,4 kg". Converter
 * para número exigiria uma coluna por tipo de medida e ainda assim
 * quebraria no primeiro formato que o usuário inventasse.
 */
class HealthDiaryEntry extends Model
{
    public const KIND_SYMPTOM     = 'symptom';
    public const KIND_APPOINTMENT = 'appointment';
    public const KIND_EXAM        = 'exam';
    public const KIND_MEDICATION  = 'medication_taken';
    public const KIND_VACCINATION = 'vaccination';
    public const KIND_NOTE        = 'note';

    public const KINDS = [
        self::KIND_SYMPTOM,
        self::KIND_APPOINTMENT,
        self::KIND_EXAM,
        self::KIND_MEDICATION,
        self::KIND_VACCINATION,
        self::KIND_NOTE,
    ];

    public const KIND_LABELS = [
        self::KIND_SYMPTOM     => 'Sintoma',
        self::KIND_APPOINTMENT => 'Consulta',
        self::KIND_EXAM        => 'Exame',
        self::KIND_MEDICATION  => 'Medicação tomada',
        self::KIND_VACCINATION => 'Vacina',
        self::KIND_NOTE        => 'Anotação',
    ];

    protected $fillable = [
        'entity_id',
        'created_by_tenant_id',
        'kind',
        'title',
        'description',
        'measure_key',
        'measure_value',
        'prescription_id',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'created_by_tenant_id');
    }

    public function kindLabel(): string
    {
        return self::KIND_LABELS[$this->kind] ?? $this->kind;
    }
}
