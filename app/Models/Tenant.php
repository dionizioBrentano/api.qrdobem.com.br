<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant — a CONTA. Não confundir com Person (a pessoa natural).
 *
 * Uma pessoa pode ter várias contas, com e-mails diferentes e o mesmo CPF
 * (TX-R03 do PLANO_TRILHAS_2026-08.md). Por isso `person_id` é o que
 * agrupa contas, e `cpf_hash` é o blind index que permite localizar sem
 * manter CPF legível — ver App\Services\CpfIdentityService.
 *
 * ALTERAÇÃO DESTA VERSÃO (Fase 0, entrega 0.8):
 *   + `person_id` e `cpf_hash` no fillable
 *   + relação `person()`
 *   + relações `ownedSpaces()` e `spaceMemberships()`
 * Nada foi removido: `cpf` (texto puro, legado) continua no fillable e
 * segue funcionando até a migration de limpeza descrita em
 * 2026_08_06_000003_create_people_table.php.
 */
class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'person_id',
        'name',
        'nickname',
        'email',
        'document_number',
        'firebase_uid',
        'role',
        'qr_quota',
        'is_active',
        'cpf',
        'cpf_hash',
        'dob',
        'phone',
        'profile_status',
        'email_verified_at',
        'address_street',
        'address_number',
        'address_complement',
        'address_neighborhood',
        'address_city',
        'address_state',
        'address_zipcode',
        'originating_conversation_id',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'dob' => 'date',
        'is_active' => 'boolean',
    ];

    /**
     * Nunca sair em resposta JSON.
     * `cpf_hash` é blind index: expô-lo permitiria a quem tivesse a chave
     * testar CPFs contra o valor até casar.
     *
     * Deliberadamente NÃO escondo `firebase_uid` aqui: ele já é serializado
     * hoje e esconder poderia quebrar tela existente. É item para revisar
     * em separado, não carona nesta entrega.
     */
    protected $hidden = [
        'cpf_hash',
    ];

    /** A pessoa natural dona desta conta (TX-R03). */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function organizations()
    {
        return $this->belongsToMany(Organization::class, 'organization_user')
                    ->withPivot('role')
                    ->withTimestamps();
    }

    /** Espaços de que esta conta é dona (F1). */
    public function ownedSpaces(): HasMany
    {
        return $this->hasMany(Space::class, 'owner_tenant_id');
    }

    /** Vínculos desta conta com espaços de terceiros (F2). */
    public function spaceMemberships(): HasMany
    {
        return $this->hasMany(SpaceMember::class);
    }

    public function documents()
    {
        return $this->hasMany(TenantDocument::class);
    }

    public function termAcceptances()
    {
        return $this->hasMany(TenantTermAcceptance::class);
    }
}
