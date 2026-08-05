<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SpaceMember — vínculo de uma conta (Tenant) com um espaço, e o que ela
 * pode fazer lá dentro. Fundação F2 do PLANO_TRILHAS_2026-08.md (T1-R03, T1-R04).
 *
 * Regra de decisão de permissão, nesta ordem:
 *   1. Membro sem `accepted_at` não exerce permissão alguma (convite pendente).
 *   2. Papel `owner` pode tudo, sempre.
 *   3. Permissão concedida explicitamente em `space_member_permissions` vale.
 *   4. Caso contrário, aplica-se o conjunto padrão do papel (ROLE_DEFAULTS).
 *
 * O passo 3 vir antes do 4 é o que permite delegação fina: um `member`
 * pode receber `entity.edit` sem virar `manager`.
 */
class SpaceMember extends Model
{
    use HasFactory, SoftDeletes;

    public const ROLE_OWNER   = 'owner';
    public const ROLE_ADMIN   = 'admin';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_MEMBER  = 'member';

    /** Vocabulário completo de permissões reconhecidas pela aplicação. */
    public const PERMISSIONS = [
        'entity.view',
        'entity.create',
        'entity.edit',
        'entity.delete',
        'member.view',
        'member.invite',
        'member.remove',
        'member.permission',
        'finance.view',
        'finance.manage',
        'panic.configure',
        'panic.trigger',
        'space.edit',
        'space.delete',
    ];

    /** Conjunto padrão por papel. `owner` não aparece: pode tudo por definição. */
    public const ROLE_DEFAULTS = [
        self::ROLE_ADMIN => [
            'entity.view', 'entity.create', 'entity.edit', 'entity.delete',
            'member.view', 'member.invite', 'member.remove', 'member.permission',
            'finance.view', 'finance.manage',
            'panic.configure', 'panic.trigger',
            'space.edit',
        ],
        self::ROLE_MANAGER => [
            'entity.view', 'entity.create', 'entity.edit',
            'member.view', 'member.invite',
            'finance.view',
            'panic.trigger',
        ],
        self::ROLE_MEMBER => [
            'entity.view',
            'member.view',
            'panic.trigger',
        ],
    ];

    protected $fillable = [
        'space_id',
        'tenant_id',
        'role',
        'invited_by_tenant_id',
        'accepted_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'invited_by_tenant_id');
    }

    public function grantedPermissions(): HasMany
    {
        return $this->hasMany(SpaceMemberPermission::class);
    }

    /** Convite ainda não aceito. */
    public function isPending(): bool
    {
        return $this->accepted_at === null;
    }

    /**
     * Decide se este membro tem a permissão indicada.
     * Ver a ordem de decisão no cabeçalho da classe.
     */
    public function can(string $permission): bool
    {
        if ($this->isPending()) {
            return false;
        }

        if ($this->role === self::ROLE_OWNER) {
            return true;
        }

        // Concessão explícita. Usa a relação já carregada quando houver,
        // para não disparar uma consulta por verificação.
        $explicit = $this->relationLoaded('grantedPermissions')
            ? $this->grantedPermissions->contains('permission', $permission)
            : $this->grantedPermissions()->where('permission', $permission)->exists();

        if ($explicit) {
            return true;
        }

        return in_array($permission, self::ROLE_DEFAULTS[$this->role] ?? [], true);
    }

    /** Lista efetiva de permissões — usada pelo endpoint que alimenta o frontend. */
    public function effectivePermissions(): array
    {
        if ($this->isPending()) {
            return [];
        }

        if ($this->role === self::ROLE_OWNER) {
            return self::PERMISSIONS;
        }

        $fromRole = self::ROLE_DEFAULTS[$this->role] ?? [];
        $explicit = $this->grantedPermissions()->pluck('permission')->all();

        return array_values(array_unique(array_merge($fromRole, $explicit)));
    }
}
