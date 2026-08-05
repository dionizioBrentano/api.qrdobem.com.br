<?php

namespace App\Http\Controllers;

use App\Models\SpaceMember;
use App\Models\Tenant;
use App\Services\CpfIdentityService;
use Illuminate\Http\Request;

/**
 * MeController — identidade da pessoa logada: suas contas e seus vínculos.
 *
 * Fase 0, entregas 0.10 e 0.11 do PLANO_TRILHAS_2026-08.md
 * (requisitos TX-R02, TX-R03, TX-R04).
 *
 * FRONTEIRA DE SEGURANÇA — a regra que dá sentido a este controller:
 * nenhum endpoint aqui aceita CPF do cliente. Tudo parte de
 * `$request->tenant`, que é o tenant provado pelo JWT do Firebase.
 * CPF não é segredo (nota fiscal, farmácia, vazamento de terceiro); um
 * endpoint "informe o CPF e veja os vínculos" seria um consultor de
 * vínculos sociais de qualquer brasileiro. Ver §3.F10 do plano.
 *
 * ENDPOINTS
 *   GET  /me/accounts        contas da mesma pessoa
 *   GET  /me/links           vínculos consolidados (espaços e papéis)
 *   POST /me/switch-account  valida a troca de conta (ver limitação abaixo)
 */
class MeController extends Controller
{
    public function __construct(private CpfIdentityService $identity)
    {
    }

    /**
     * GET /me/accounts
     *
     * Lista as contas ligadas à mesma pessoa natural (mesmo CPF), incluindo
     * a atual. Conta sem CPF ainda cadastrado aparece sozinha — é o estado
     * normal de quem não concluiu o Gate 1.
     */
    public function accounts(Request $request)
    {
        $tenant = $request->tenant;

        $accounts = $this->identity->accountsOf($tenant)->map(function (Tenant $account) use ($tenant) {
            return [
                'id'             => $account->id,
                'name'           => $account->name,
                'nickname'       => $account->nickname,
                'email'          => $account->email,
                'role'           => $account->role,
                'profile_status' => $account->profile_status,
                'is_current'     => $account->id === $tenant->id,
                'created_at'     => $account->created_at,
            ];
        })->values();

        return response()->json([
            'person_id'   => $tenant->person_id,
            'linked'      => $tenant->person_id !== null,
            'accounts'    => $accounts,
            'total'       => $accounts->count(),
        ]);
    }

    /**
     * GET /me/links
     *
     * Vínculos consolidados da PESSOA (não apenas da conta ativa): em que
     * espaços ela aparece, com que papel e por qual conta.
     *
     * É a resposta à pergunta "onde meu CPF está sendo usado?" (TX-R02),
     * respondida sem que ninguém precise digitar um CPF.
     */
    public function links(Request $request)
    {
        $tenant = $request->tenant;

        $accounts = $this->identity->accountsOf($tenant);
        $accountIds = $accounts->pluck('id');
        $accountsById = $accounts->keyBy('id');

        $memberships = SpaceMember::with('space')
            ->whereIn('tenant_id', $accountIds)
            ->whereNull('deleted_at')
            ->get();

        $links = $memberships
            // Vínculo cujo espaço foi apagado não tem o que exibir.
            ->filter(fn (SpaceMember $member) => $member->space !== null)
            ->map(function (SpaceMember $member) use ($accountsById) {
                $account = $accountsById->get($member->tenant_id);

                return [
                    'space_id'      => $member->space->id,
                    'space_name'    => $member->space->name,
                    'space_type'    => $member->space->type,
                    'space_slug'    => $member->space->slug,
                    'role'          => $member->role,
                    'pending'       => $member->isPending(),
                    'permissions'   => $member->effectivePermissions(),
                    'through_account' => [
                        'id'    => $member->tenant_id,
                        'email' => $account?->email,
                    ],
                ];
            })
            ->values();

        // Agrupado por tipo de trilha: é como o usuário pensa os vínculos
        // ("minhas famílias", "minhas causas"), não como uma lista plana.
        $byType = $links->groupBy('space_type')->map->count();

        return response()->json([
            'links'      => $links,
            'total'      => $links->count(),
            'by_type'    => $byType,
            'accounts'   => $accountIds->values(),
        ]);
    }

    /**
     * POST /me/switch-account  { target_tenant_id }
     *
     * Valida que a conta de destino pertence à MESMA pessoa e devolve o que
     * o frontend precisa para concluir a troca.
     *
     * LIMITAÇÃO REAL, REGISTRADA EM VEZ DE DISFARÇADA:
     * a identidade do usuário neste sistema é o JWT do Firebase (ver
     * App\Http\Middleware\FirebaseAuth). O backend verifica esse token com
     * as chaves públicas do Google, mas NÃO tem credencial de serviço do
     * Firebase Admin SDK — portanto não consegue emitir um token para outra
     * conta. Sem isso, "trocar de conta sem logout" não pode ser um swap de
     * token feito aqui.
     *
     * O que este endpoint entrega hoje: a confirmação de que a troca é
     * legítima (mesma pessoa) e o e-mail de destino, para o frontend
     * conduzir a reautenticação sem o usuário ter de lembrar em qual conta
     * estava. É troca assistida, não troca silenciosa.
     *
     * O que falta para ser silenciosa: decisão D10 do plano — adotar o
     * Firebase Admin SDK com service account para emitir custom tokens.
     * É decisão de arquitetura e custo operacional, não linha de código.
     */
    public function switchAccount(Request $request)
    {
        $tenant = $request->tenant;

        $validated = $request->validate([
            'target_tenant_id' => 'required|integer',
        ]);

        // Sem pessoa vinculada não há a quem trocar.
        if (!$tenant->person_id) {
            return response()->json([
                'error' => 'Esta conta ainda não tem CPF verificado, então não há contas vinculadas.',
            ], 422);
        }

        $target = Tenant::where('id', $validated['target_tenant_id'])
            ->where('person_id', $tenant->person_id)
            ->first();

        // Mensagem propositalmente idêntica para "não existe" e "não é sua":
        // diferenciar permitiria descobrir a existência de contas alheias.
        if (!$target) {
            return response()->json([
                'error' => 'Conta de destino não encontrada entre as suas contas.',
            ], 404);
        }

        if ($target->id === $tenant->id) {
            return response()->json([
                'error' => 'Você já está nesta conta.',
            ], 422);
        }

        return response()->json([
            'target' => [
                'id'    => $target->id,
                'name'  => $target->name,
                'email' => $target->email,
            ],
            // O frontend usa isto para decidir o fluxo. Enquanto for
            // 'reauth', ele leva o usuário ao login com o e-mail preenchido.
            'method'  => 'reauth',
            'message' => 'Confirme a senha da conta de destino para concluir a troca.',
        ]);
    }
}
