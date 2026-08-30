<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Entity — o registro por trás de um QR Code (pessoa, pet ou objeto).
 *
 * ALTERAÇÃO DESTA VERSÃO (Fase 0, entrega 0.1 do PLANO_TRILHAS_2026-08.md):
 *   + `space_id` no fillable
 *   + relação `space()`
 *
 * `organization_id` foi mantido intacto de propósito. Durante a transição
 * as duas colunas coexistem: o backfill (`php artisan spaces:backfill`)
 * preenche `space_id` a partir da organização, e só na entrega 0.5 os
 * controllers passam a consultar por espaço. Remover a coluna antiga antes
 * disso deixaria o sistema em produção sem caminho de volta.
 */
class Entity extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        // Espaço ao qual a entidade pertence (F1). Nullable durante a
        // transição; passa a obrigatório em migration futura.
        'space_id',
        'credit_batch_id',
        'unique_code',
        'type',
        'qr_caption',
        'encrypted_name',
        'encrypted_contact_phone',
        'encrypted_contact_email',
        'encrypted_medical_info',
        'encrypted_additional_info',
        'is_active',
        // Sem isso o Eloquent descarta o status e toda entidade nasce
        // como 'pending_term', mesmo com o termo aceito.
        'status',
        'silent_password_hash',
    ];

    protected $hidden = [
        'silent_password_hash',
    ];

    protected $casts = [
        'encrypted_name' => 'encrypted',
        'encrypted_contact_phone' => 'encrypted',
        'encrypted_contact_email' => 'encrypted',
        'encrypted_medical_info' => 'encrypted',
        'encrypted_additional_info' => 'encrypted',
        'is_active' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creditBatch(): BelongsTo
    {
        return $this->belongsTo(CreditBatch::class, 'credit_batch_id');
    }

    /** Espaço de trilha ao qual a entidade pertence (F1). */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function customAttributes(): MorphMany
    {
        return $this->morphMany(CustomAttribute::class, 'owner');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(EntityConversation::class);
    }

    public function healthFields(): HasMany
    {
        return $this->hasMany(EntityHealthField::class);
    }

    public function petFields(): HasOne
    {
        return $this->hasOne(EntityPetField::class);
    }

    public function vaccinations(): HasMany
    {
        return $this->hasMany(EntityVaccination::class);
    }

    public function objectFields(): HasOne
    {
        return $this->hasOne(EntityObjectField::class);
    }

    public function entityReads(): HasMany
    {
        return $this->hasMany(EntityRead::class);
    }

    public function entityAlerts(): HasMany
    {
        return $this->hasMany(EntityAlert::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(MediaItem::class, 'owner_id')
                    ->where('owner_type', MediaItem::OWNER_ENTITY);
    }

    public function referencePoints(): HasMany
    {
        return $this->hasMany(EntityReferencePoint::class);
    }

    public function routines(): HasMany
    {
        return $this->hasMany(Routine::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(EntityPosition::class);
    }
}
