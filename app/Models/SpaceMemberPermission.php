<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SpaceMemberPermission — concessão explícita de permissão a um membro do
 * espaço. Fundação F2 do PLANO_TRILHAS_2026-08.md.
 *
 * Existe para atender T1-R04 (delegação de poderes pelo fundador do grupo
 * familiar): sem ela, delegar exigiria promover o membro a um papel inteiro,
 * concedendo junto poderes que não se pretendia dar.
 *
 * `granted_by_tenant_id` não é decoração: delegação de poder sobre dados de
 * familiares precisa de trilha de quem concedeu o quê.
 *
 * O vocabulário válido está em SpaceMember::PERMISSIONS. A validação é feita
 * na aplicação, de propósito — permissão nova não deve exigir migration.
 */
class SpaceMemberPermission extends Model
{
    protected $fillable = [
        'space_member_id',
        'permission',
        'granted_by_tenant_id',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(SpaceMember::class, 'space_member_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'granted_by_tenant_id');
    }
}
