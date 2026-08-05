<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Space — unidade de produto do sistema (fundação F1 do PLANO_TRILHAS_2026-08.md).
 *
 * Responde "em que contexto estou operando": família, causa, empresa ou
 * doação. Não confundir com Organization, que responde "qual pessoa
 * jurídica está por trás" (CNPJ, OSCIP), nem com Person, que responde
 * "quem eu sou por trás das minhas contas".
 *
 * @property int         $id
 * @property int         $owner_tenant_id
 * @property int|null    $organization_id
 * @property int|null    $parent_space_id
 * @property string      $type    family|cause|company|donation
 * @property string      $name
 * @property string      $slug
 * @property array|null  $settings
 * @property string      $status  active|suspended
 */
class Space extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_FAMILY   = 'family';
    public const TYPE_CAUSE    = 'cause';
    public const TYPE_COMPANY  = 'company';
    public const TYPE_DONATION = 'donation';

    public const TYPES = [
        self::TYPE_FAMILY,
        self::TYPE_CAUSE,
        self::TYPE_COMPANY,
        self::TYPE_DONATION,
    ];

    protected $fillable = [
        'owner_tenant_id',
        'organization_id',
        'parent_space_id',
        'type',
        'name',
        'slug',
        'settings',
        'status',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    /** Dono do espaço — na Trilha 1, o fundador do grupo familiar (T1-R03). */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'owner_tenant_id');
    }

    /** Vínculo jurídico/fiscal, quando existir. Nulo em iniciativa de CPF (T2-R01). */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Espaço guarda-chuva que apoia este (T2-R02). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'parent_space_id');
    }

    /** Espaços apoiados por este, quando ele é o guarda-chuva (T2-R02). */
    public function children(): HasMany
    {
        return $this->hasMany(Space::class, 'parent_space_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(SpaceMember::class);
    }

    /** Vitrine pública — existe só para espaço do tipo `cause` (T2-R04). */
    public function causeProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(CauseProfile::class);
    }

    /** Lotes de QR para impressão (T2-R03). */
    public function printBatches(): HasMany
    {
        return $this->hasMany(QrPrintBatch::class);
    }

    public function entities(): HasMany
    {
        return $this->hasMany(Entity::class);
    }

    /**
     * Lê uma configuração do espaço (white-label, flags, tetos).
     * Mantido aqui para que nenhum controller precise saber a forma do json.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    /**
     * Gera um slug único a partir de um nome.
     * O sufixo numérico só aparece em caso de colisão real, para que o
     * primeiro espaço de cada nome fique com a URL limpa.
     */
    public static function generateSlug(string $name): string
    {
        $base = \Illuminate\Support\Str::slug($name) ?: 'espaco';
        $slug = $base;
        $suffix = 2;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }
}
