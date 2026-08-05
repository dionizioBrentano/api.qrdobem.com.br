<?php

namespace App\Policies;

use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\Tenant;

/**
 * SpacePolicy — decide o que uma conta pode fazer dentro de um espaço.
 * Fundação F2 do PLANO_TRILHAS_2026-08.md (T1-R03, T1-R04).
 *
 * Esta policy NÃO é registrada como Gate do Laravel de propósito: o sistema
 * autentica por JWT do Firebase e trabalha com `$request->tenant`, não com
 * `auth()->user()`. Registrá-la no AuthServiceProvider criaria a ilusão de
 * que `$this->authorize()` funciona nos controllers, e não funcionaria.
 * Use-a explicitamente:
 *
 *     app(SpacePolicy::class)->authorize($request->tenant, $space, 'entity.create');
 *
 * Regras, nesta ordem:
 *   1. Superadmin passa (papel global do tenant, não do espaço).
 *   2. Dono do espaço passa.
 *   3. Membro com convite aceito, conforme SpaceMember::can().
 *   4. Caso contrário, negado.
 */
class SpacePolicy
{
    /**
     * Verificação silenciosa — devolve true/false.
     */
    public function check(Tenant $tenant, Space $space, string $permission): bool
    {
        if ($tenant->role === 'superadmin') {
            return true;
        }

        if ($space->owner_tenant_id === $tenant->id) {
            return true;
        }

        $member = $this->memberOf($tenant, $space);

        return $member?->can($permission) ?? false;
    }

    /**
     * Verificação que interrompe a requisição com 403.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function authorize(Tenant $tenant, Space $space, string $permission): void
    {
        if (!$this->check($tenant, $space, $permission)) {
            abort(403, 'Você não tem permissão para esta ação neste espaço.');
        }
    }

    /**
     * Permissões efetivas da conta neste espaço.
     * Usado para o frontend desabilitar botão em vez de deixar o usuário
     * descobrir a restrição no 403.
     */
    public function permissionsFor(Tenant $tenant, Space $space): array
    {
        if ($tenant->role === 'superadmin' || $space->owner_tenant_id === $tenant->id) {
            return SpaceMember::PERMISSIONS;
        }

        return $this->memberOf($tenant, $space)?->effectivePermissions() ?? [];
    }

    /**
     * Vínculo da conta com o espaço, com as concessões explícitas já
     * carregadas para evitar uma consulta por permissão verificada.
     */
    private function memberOf(Tenant $tenant, Space $space): ?SpaceMember
    {
        return SpaceMember::with('grantedPermissions')
            ->where('space_id', $space->id)
            ->where('tenant_id', $tenant->id)
            ->first();
    }
}
