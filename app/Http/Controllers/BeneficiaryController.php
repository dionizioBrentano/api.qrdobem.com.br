<?php

namespace App\Http\Controllers;

use App\Models\Beneficiary;
use App\Models\BeneficiaryNeed;
use App\Models\Space;
use App\Policies\SpacePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * BeneficiaryController — beneficiários e suas necessidades.
 * Fase 4, T4-R05 e T4-R09 do PLANO_TRILHAS_2026-08.md.
 *
 * A URL ÚNICA É OBRIGATÓRIA (requisito literal)
 * "O beneficiário OBRIGATORIAMENTE deve usar sua URL única para: solicitar
 * suas necessidades; comprovar o recebimento; registrar provas sociais."
 * Por isso as rotas de solicitação são PÚBLICAS e endereçadas por
 * `unique_code` — quem tem o código é quem tem a URL.
 *
 * TUTOR (T4-R09)
 * Beneficiário sem autonomia digital acessa por um tutor identificado. A
 * confirmação feita pelo tutor é registrada COMO tutor — nunca se passa
 * por confirmação do próprio beneficiário. É a diferença entre um registro
 * auditável e uma fraude documentada.
 *
 * ENDPOINTS AUTENTICADOS (gestão pela causa)
 *   POST /spaces/{space}/beneficiaries
 *   GET  /spaces/{space}/beneficiaries
 *   PUT  /beneficiaries/{id}
 *   POST /beneficiaries/{id}/proof-password
 *
 * ENDPOINTS PÚBLICOS (a URL única do beneficiário)
 *   GET  /b/{unique_code}
 *   POST /b/{unique_code}/needs
 */
class BeneficiaryController extends Controller
{
    /** POST /spaces/{space}/beneficiaries */
    public function store(Request $request, $spaceId)
    {
        $space = Space::find($spaceId);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'finance.manage');

        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'document'        => 'sometimes|nullable|string|max:20',
            'phone'           => 'sometimes|nullable|string|max:30',
            'city'            => 'sometimes|nullable|string|max:120',
            'state'           => 'sometimes|nullable|string|size:2',
            'tutor_tenant_id' => 'sometimes|nullable|integer',
            'proof_factors'   => 'sometimes|array',
            'proof_factors.*' => 'string|in:' . implode(',', Beneficiary::FACTORS),
        ]);

        $beneficiary = Beneficiary::create([
            'space_id'               => $space->id,
            'unique_code'            => (string) Str::uuid(),
            'name'                   => $validated['name'],
            'encrypted_document'     => $validated['document'] ?? null,
            'phone'                  => $validated['phone'] ?? null,
            'city'                   => $validated['city'] ?? null,
            'state'                  => $validated['state'] ?? null,
            'tutor_tenant_id'        => $validated['tutor_tenant_id'] ?? null,
            // Sem escolha explícita, vale a senha. O tutor é somado
            // automaticamente quando existe — ver Beneficiary::factors().
            'accepted_proof_factors' => $validated['proof_factors'] ?? [Beneficiary::FACTOR_PASSWORD],
            'status'                 => 'active',
        ]);

        return response()->json([
            'message'     => 'Beneficiário cadastrado.',
            'beneficiary' => [
                'id'          => $beneficiary->id,
                'name'        => $beneficiary->name,
                'unique_code' => $beneficiary->unique_code,
                // A URL que o beneficiário vai usar para tudo.
                'url'         => config('qrdobem.frontend_url') . '/b/' . $beneficiary->unique_code,
                'factors'     => $beneficiary->factors(),
            ],
        ], 201);
    }

    /** GET /spaces/{space}/beneficiaries */
    public function index(Request $request, $spaceId)
    {
        $space = Space::find($spaceId);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'finance.view');

        $beneficiaries = Beneficiary::with('needs')
            ->where('space_id', $space->id)
            ->orderBy('name')
            ->get();

        return response()->json([
            'beneficiaries' => $beneficiaries->map(fn (Beneficiary $b) => [
                'id'          => $b->id,
                'name'        => $b->name,
                'city'        => $b->city,
                'state'       => $b->state,
                'status'      => $b->status,
                'unique_code' => $b->unique_code,
                'url'         => config('qrdobem.frontend_url') . '/b/' . $b->unique_code,
                'factors'     => $b->factors(),
                'has_tutor'   => $b->tutor_tenant_id !== null,
                'open_needs'  => $b->needs->where('status', 'open')->count(),
            ])->values(),
        ]);
    }

    /** PUT /beneficiaries/{id} */
    public function update(Request $request, $beneficiaryId)
    {
        $beneficiary = Beneficiary::find($beneficiaryId);

        if (!$beneficiary) {
            return response()->json(['error' => 'Beneficiário não encontrado.'], 404);
        }

        $space = Space::find($beneficiary->space_id);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'finance.manage');

        $validated = $request->validate([
            'name'            => 'sometimes|string|max:255',
            'phone'           => 'sometimes|nullable|string|max:30',
            'city'            => 'sometimes|nullable|string|max:120',
            'state'           => 'sometimes|nullable|string|size:2',
            'tutor_tenant_id' => 'sometimes|nullable|integer',
            'bank_info'       => 'sometimes|nullable|string|max:500',
            'status'          => 'sometimes|string|in:active,suspended',
            'proof_factors'   => 'sometimes|array',
            'proof_factors.*' => 'string|in:' . implode(',', Beneficiary::FACTORS),
        ]);

        if (array_key_exists('bank_info', $validated)) {
            $validated['encrypted_bank_info'] = $validated['bank_info'];
            unset($validated['bank_info']);
        }

        if (array_key_exists('proof_factors', $validated)) {
            $validated['accepted_proof_factors'] = $validated['proof_factors'];
            unset($validated['proof_factors']);
        }

        $beneficiary->update($validated);

        return response()->json([
            'message'     => 'Beneficiário atualizado.',
            'beneficiary' => [
                'id'      => $beneficiary->id,
                'name'    => $beneficiary->name,
                'status'  => $beneficiary->status,
                'factors' => $beneficiary->fresh()->factors(),
            ],
        ]);
    }

    /**
     * POST /beneficiaries/{id}/proof-password  { password }
     *
     * Define ou redefine a senha pessoal da contraprova (T4-R06).
     * Quem define é a gestão da causa, não o beneficiário: ele frequentemente
     * não tem e-mail próprio para um fluxo de "esqueci minha senha", e essa
     * era justamente a fragilidade que o modelo de múltiplos fatores resolve.
     */
    public function setProofPassword(Request $request, $beneficiaryId)
    {
        $beneficiary = Beneficiary::find($beneficiaryId);

        if (!$beneficiary) {
            return response()->json(['error' => 'Beneficiário não encontrado.'], 404);
        }

        $space = Space::find($beneficiary->space_id);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'finance.manage');

        $validated = $request->validate([
            'password' => 'required|string|min:4|max:64',
        ]);

        $beneficiary->setProofPassword($validated['password']);

        return response()->json([
            'message' => 'Senha de comprovação definida.',
            // A senha não volta na resposta, nem mesmo para quem a definiu:
            // ela precisa ser entregue de viva voz ao beneficiário.
            'factors' => $beneficiary->fresh()->factors(),
        ]);
    }

    /**
     * GET /b/{unique_code}  — PÚBLICO
     * A página do beneficiário: o que ele pediu e o que está a caminho.
     */
    public function publicShow(Request $request, $uniqueCode)
    {
        $beneficiary = Beneficiary::with(['needs', 'disbursements'])
            ->where('unique_code', $uniqueCode)
            ->where('status', 'active')
            ->first();

        if (!$beneficiary) {
            return response()->json(['error' => 'Página não encontrada.'], 404);
        }

        return response()->json([
            'beneficiary' => [
                'name'    => $beneficiary->name,
                'city'    => $beneficiary->city,
                'state'   => $beneficiary->state,
                'factors' => $beneficiary->factors(),
            ],
            'needs' => $beneficiary->needs
                ->whereIn('status', ['open', 'in_progress'])
                ->map(fn (BeneficiaryNeed $n) => [
                    'id'          => $n->id,
                    'title'       => $n->title,
                    'description' => $n->description,
                    'kind'        => $n->kind,
                    'status'      => $n->status,
                    'priority'    => $n->priority,
                ])->values(),
            // Repasses a caminho: é aqui que ele vê o que precisa confirmar.
            'pending_confirmation' => $beneficiary->disbursements
                ->where('status', 'sent')
                ->map(fn ($d) => [
                    'id'          => $d->id,
                    'description' => $d->description,
                    'kind'        => $d->kind,
                    'amount'      => $d->amount,
                    'sent_at'     => $d->sent_at,
                ])->values(),
        ]);
    }

    /**
     * POST /b/{unique_code}/needs  — PÚBLICO
     * O beneficiário solicita o que precisa (T4-R05).
     *
     * Público porque a URL única é a credencial: quem tem o link é o
     * beneficiário (ou seu tutor). Throttle aplicado na rota para conter
     * abuso de quem descobrir um código.
     */
    public function storeNeed(Request $request, $uniqueCode)
    {
        $beneficiary = Beneficiary::where('unique_code', $uniqueCode)
            ->where('status', 'active')
            ->first();

        if (!$beneficiary) {
            return response()->json(['error' => 'Página não encontrada.'], 404);
        }

        $validated = $request->validate([
            'title'            => 'required|string|max:255',
            'description'      => 'sometimes|nullable|string|max:2000',
            'kind'             => 'sometimes|string|in:' . implode(',', BeneficiaryNeed::KINDS),
            'estimated_amount' => 'sometimes|nullable|numeric|min:0',
            'priority'         => 'sometimes|integer|min:1|max:5',
        ]);

        // Teto de pedidos em aberto: sem isso, um código vazado viraria
        // enxurrada de solicitações e a gestão da causa perderia o fio.
        $openCount = BeneficiaryNeed::where('beneficiary_id', $beneficiary->id)
            ->where('status', 'open')
            ->count();

        if ($openCount >= 20) {
            return response()->json([
                'error' => 'Há muitos pedidos em aberto. Aguarde o atendimento dos atuais.',
                'code'  => 'TOO_MANY_OPEN_NEEDS',
            ], 422);
        }

        $need = BeneficiaryNeed::create([
            'beneficiary_id'   => $beneficiary->id,
            'title'            => $validated['title'],
            'description'      => $validated['description'] ?? null,
            'kind'             => $validated['kind'] ?? BeneficiaryNeed::KIND_PRODUCT,
            'estimated_amount' => $validated['estimated_amount'] ?? null,
            'priority'         => $validated['priority'] ?? 3,
            'status'           => 'open',
        ]);

        return response()->json([
            'message' => 'Pedido registrado.',
            'need'    => [
                'id'     => $need->id,
                'title'  => $need->title,
                'status' => $need->status,
            ],
        ], 201);
    }
}
