<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Prescription — medicação de uso contínuo de um perfil.
 * Fase 6, T1-R10 e T1-R11 do PLANO_TRILHAS_2026-08.md.
 *
 * `schedule_times` guarda os horários calculados. Guardados, e não
 * recalculados a cada leitura, por dois motivos: o `.ics` não precisa
 * recalcular a cada exportação, e o usuário pode ajustar um horário
 * manualmente sem que o ajuste se perca na próxima abertura da tela.
 *
 * `suggested_from_leaflet` registra se o intervalo veio da bula. A tela usa
 * isso para exibir a fonte junto com a sugestão — o cruzamento bula ×
 * prescrição é ASSISTIVO. O sistema sugere, mostra de onde tirou, e exige
 * confirmação. Nunca decide sozinho.
 */
class Prescription extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'medication_id',
        'medication_name',
        'dosage',
        'interval_hours',
        'first_dose_at',
        'starts_on',
        'ends_on',
        'notes',
        'prescriber',
        'schedule_times',
        'suggested_from_leaflet',
        'is_active',
    ];

    protected $casts = [
        'schedule_times'         => 'array',
        'suggested_from_leaflet' => 'boolean',
        'is_active'              => 'boolean',
        'starts_on'              => 'date',
        'ends_on'                => 'date',
        'interval_hours'         => 'integer',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }

    public function diaryEntries(): HasMany
    {
        return $this->hasMany(HealthDiaryEntry::class);
    }

    /** Uso contínuo = sem data de término. */
    public function isContinuous(): bool
    {
        return $this->ends_on === null;
    }

    /**
     * Calcula os horários do dia a partir do intervalo e da primeira dose.
     *
     * Ex.: primeira dose 06:00, intervalo 8h → 06:00, 14:00, 22:00.
     *
     * O laço para em 24h para não gerar horário do dia seguinte: a agenda
     * repete diariamente, e duplicar a última dose confundiria o usuário
     * justamente no momento em que ele confere se já tomou.
     */
    public function calculateSchedule(): array
    {
        if (!$this->interval_hours || $this->interval_hours < 1) {
            return [];
        }

        $first = $this->first_dose_at
            ? \Carbon\Carbon::parse($this->first_dose_at)
            : \Carbon\Carbon::createFromTime(8, 0);

        $times = [];
        $elapsed = 0;

        while ($elapsed < 24) {
            $times[] = $first->copy()->addHours($elapsed)->format('H:i');
            $elapsed += $this->interval_hours;
        }

        return $times;
    }
}
