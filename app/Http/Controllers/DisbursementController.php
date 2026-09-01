<?php

namespace App\Http\Controllers;

use App\Models\Beneficiary;
use App\Models\BeneficiaryNeed;
use App\Models\Disbursement;
use App\Models\MediaItem;
use App\Models\Space;
use App\Policies\SpacePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * DisbursementController — repasses e CONTRAPROVA.
 * Fase 4, T4-R03, T4-R06, T4-R07 e T4-R08 do PLANO_TRILHAS_2026-08.md.
 *
 * ESTE É O CONTROLLER MAIS SENSÍVEL DO SISTEMA.
 * O requisito é explícito: para evitar fraude, o repasse exige validação
 * sistêmica, e o beneficiário obrigatoriamente comprova o recebimento
 * escaneando o QR de validação e apresentando um fator de autenticação.
 *
 * MÁQUINA DE ESTADOS — respeitada em toda transição
 *   requested → approved → sent → confirmed
 *                            └──→ disputed
 * `confirmed` é o único estado que conta como repasse concluído. As
 * transições válidas vivem em Disbursement::TRANSITIONS, e não em `if`
 * espalhado por aqui: regra de estado em um lugar só é o que impede um
 * caminho novo pular uma etapa sem ninguém perceber.
 *
 * TRÊS FATORES DE CONTRAPROVA (F9)
 *   password → senha pessoal do beneficiário
 *   tutor    → tutor autenticado confirma POR ele, registrado como tutor
 *   facial   → reservado; sem fornecedor definido (decisão D8), o endpoint
 *              recusa em vez de fingir que validou
 *
 * O terceiro caso merece atenção: aceitar `facial` sem verificação real
 * seria pior que não ter o fator — geraria prova falsa num sistema cuja
 * razão de existir é a prova.
 */
class DisbursementController extends Controller
{
    /** POST /spaces/{space}/disbursements */
    public function store(Request $request, $spaceId)
    {
        $space = Space::find($spaceId);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'finance.manage');

        $validated = $request->validate([
            'beneficiary_id' => 'required|integer',
            'need_id'        => 'sometimes|nullable|integer',
            'kind'           => 'required|string|in:product,service,money',
            'description'    => 'required|string|max:500',
            'amount'         => 'sometimes|nullable|numeric|min:0',
        ]);

        $beneficiary = Beneficiary::where('id', $validated['beneficiary_id'])
            ->where('space_id', $space->id)
            ->first();

        if (!$beneficiary) {
            return response()->json([
                'error' => 'Beneficiário não pertence a este espaço.',
                'code'  => 'BENEFICIARY_NOT_IN_SPACE',
            ], 422);
        }

        // Repasse em dinheiro sem conta vinculada não sai do lugar: o
        // requisito fala em "conta bancária vinculada", e prometer um
        // repasse sem destino é gerar expectativa que não se cumpre.
        if ($validated['kind'] === 'money' && !$beneficiary->encrypted_bank_info) {
            return response()->json([
                'error' => 'Este beneficiário não tem conta bancária cadastrada.',
                'code'  => 'NO_BANK_INFO',
            ], 422);
        }

        $disbursement = Disbursement::create([
            'beneficiary_id' => $beneficiary->id,
            'need_id'        => $validated['need_id'] ?? null,
            'cause_space_id' => $space->id,
            'kind'           => $validated['kind'],
            'description'    => $validated['description'],
            'amount'         => $validated['amount'] ?? null,
            'status'         => Disbursement::STATUS_REQUESTED,
        ]);

        return response()->json([
            'message'      => 'Repasse registrado.',
            'disbursement' => $this->present($disbursement),
        ], 201);
    }

    /** GET /spaces/{space}/disbursements */
    public function index(Request $request, $spaceId)
    {
        $space = Space::find($spaceId);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'finance.view');

        $disbursements = Disbursement::with('beneficiary')
            ->where('cause_space_id', $space->id)
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json([
            'disbursements' => $disbursements->map(fn (Disbursement $d) => array_merge(
                $this->present($d),
                ['beneficiary_name' => $d->beneficiary?->name]
            ))->values(),
            // Só o confirmado conta como repassado. O resto é promessa.
            'total_confirmed' => $disbursements
                ->where('status', Disbursement::STATUS_CONFIRMED)
                ->sum('amount'),
        ]);
    }

    /**
     * POST /disbursements/{id}/transition  { status, reason? }
     * Avança o repasse na máquina de estados.
     */
    public function transition(Request $request, $disbursementId)
    {
        $disbursement = Disbursement::find($disbursementId);

        if (!$disbursement) {
            return response()->json(['error' => 'Repasse não encontrado.'], 404);
        }

        $space = Space::find($disbursement->cause_space_id);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'finance.manage');

        $validated = $request->validate([
            'status' => 'required|string|in:approved,sent,disputed',
        ]);

        $target = $validated['status'];

        // A regra de transição vive no model. Aqui só se obedece.
        if (!$disbursement->canTransitionTo($target)) {
            return response()->json([
                'error'   => "Não é possível ir de '{$disbursement->status}' para '{$target}'.",
                'code'    => 'INVALID_TRANSITION',
                'allowed' => Disbursement::TRANSITIONS[$disbursement->status] ?? [],
            ], 422);
        }

        $changes = ['status' => $target];

        if ($target === Disbursement::STATUS_APPROVED) {
            $changes['approved_by_tenant_id'] = $request->tenant->id;
            $changes['approved_at'] = now();
        }

        if ($target === Disbursement::STATUS_SENT) {
            $changes['sent_at'] = now();
        }

        $disbursement->update($changes);

        // Marca a necessidade como em atendimento quando o repasse sai.
        if ($target === Disbursement::STATUS_SENT && $disbursement->need_id) {
            BeneficiaryNeed::where('id', $disbursement->need_id)
                ->where('status', 'open')
                ->update(['status' => 'in_progress']);
        }

        return response()->json([
            'message'      => 'Repasse atualizado.',
            'disbursement' => $this->present($disbursement->fresh()),
        ]);
    }

    /**
     * POST /b/{unique_code}/disbursements/{id}/confirm  — PÚBLICO
     * Body: factor, password?
     *
     * A CONTRAPROVA (T4-R06). O beneficiário chega aqui pela sua URL única
     * — que é o "escanear o QR de validação" do requisito — e apresenta o
     * fator. Sem isso, o repasse jamais chega a `confirmed`.
     *
     * A rota é pública porque o beneficiário não tem conta no sistema. A
     * segurança vem da combinação: código único (que ele tem) + fator
     * (que só ele ou o tutor sabem) + throttle na rota.
     */
    public function confirm(Request $request, $uniqueCode, $disbursementId)
    {
        $beneficiary = Beneficiary::where('unique_code', $uniqueCode)
            ->where('status', 'active')
            ->first();

        if (!$beneficiary) {
            return response()->json(['error' => 'Página não encontrada.'], 404);
        }

        $disbursement = Disbursement::where('id', $disbursementId)
            ->where('beneficiary_id', $beneficiary->id)
            ->first();

        if (!$disbursement) {
            return response()->json(['error' => 'Repasse não encontrado.'], 404);
        }

        if (!$disbursement->canTransitionTo(Disbursement::STATUS_CONFIRMED)) {
            return response()->json([
                'error' => 'Este repasse não está aguardando confirmação.',
                'code'  => 'INVALID_TRANSITION',
                'status' => $disbursement->status,
            ], 422);
        }

        $validated = $request->validate([
            'factor'   => 'required|string|in:' . implode(',', Beneficiary::FACTORS),
            'password' => 'sometimes|nullable|string|max:64',
        ]);

        $factor = $validated['factor'];

        if (!$beneficiary->acceptsFactor($factor)) {
            return response()->json([
                'error'   => 'Este método de confirmação não está habilitado.',
                'code'    => 'FACTOR_NOT_ACCEPTED',
                'accepted' => $beneficiary->factors(),
            ], 422);
        }

        $confirmedByTenantId = null;

        switch ($factor) {
            case Beneficiary::FACTOR_PASSWORD:
                if (empty($validated['password']) || !$beneficiary->checkProofPassword($validated['password'])) {
                    return response()->json([
                        'error' => 'Senha incorreta.',
                        'code'  => 'INVALID_PASSWORD',
                    ], 422);
                }
                break;

            case Beneficiary::FACTOR_TUTOR:
                // O tutor precisa estar autenticado: é ele quem responde
                // pela confirmação, e sem identificar quem confirmou a
                // prova não vale nada.
                $tenant = $request->tenant ?? null;

                if (!$tenant || $tenant->id !== $beneficiary->tutor_tenant_id) {
                    return response()->json([
                        'error' => 'A confirmação por tutor exige que o tutor esteja autenticado.',
                        'code'  => 'TUTOR_NOT_AUTHENTICATED',
                    ], 403);
                }

                $confirmedByTenantId = $tenant->id;
                break;

            case Beneficiary::FACTOR_FACIAL:
                // Recusado de propósito enquanto não houver fornecedor
                // definido (decisão D8). Aceitar sem verificar geraria
                // prova falsa num sistema cuja razão de existir é a prova.
                return response()->json([
                    'error' => 'Confirmação por biometria facial ainda não está disponível.',
                    'code'  => 'FACTOR_UNAVAILABLE',
                ], 501);
        }

        DB::transaction(function () use ($disbursement, $factor, $confirmedByTenantId, $request) {
            $disbursement->update([
                'status'                 => Disbursement::STATUS_CONFIRMED,
                // Guardar QUAL fator foi usado é o que permite auditar
                // depois se quem confirmou foi o beneficiário ou o tutor.
                'proof_factor'           => $factor,
                'confirmed_by_tenant_id' => $confirmedByTenantId,
                'confirmed_at'           => now(),
                'confirmation_ip'        => $request->ip(),
            ]);

            if ($disbursement->need_id) {
                BeneficiaryNeed::where('id', $disbursement->need_id)
                    ->update(['status' => 'fulfilled']);
            }
        });

        return response()->json([
            'message'      => 'Recebimento confirmado. Obrigado!',
            'disbursement' => $this->present($disbursement->fresh()),
        ]);
    }

    /**
     * POST /b/{unique_code}/disbursements/{id}/proof  — PÚBLICO, multipart
     * Prova social: foto ou vídeo de agradecimento (T4-R07).
     *
     * Só aceita depois da confirmação: prova de agradecimento por algo que
     * não se confirmou ter recebido é justamente o tipo de evidência que a
     * trilha existe para não produzir.
     *
     * Nasce `pending` como toda mídia. Aqui a moderação importa ainda mais
     * — são fotos de pessoas em situação de vulnerabilidade.
     */
    public function storeProof(Request $request, $uniqueCode, $disbursementId)
    {
        $beneficiary = Beneficiary::where('unique_code', $uniqueCode)
            ->where('status', 'active')
            ->first();

        if (!$beneficiary) {
            return response()->json(['error' => 'Página não encontrada.'], 404);
        }

        $disbursement = Disbursement::where('id', $disbursementId)
            ->where('beneficiary_id', $beneficiary->id)
            ->first();

        if (!$disbursement) {
            return response()->json(['error' => 'Repasse não encontrado.'], 404);
        }

        if (!$disbursement->isConfirmed()) {
            return response()->json([
                'error' => 'Confirme o recebimento antes de enviar a prova social.',
                'code'  => 'NOT_CONFIRMED',
            ], 422);
        }

        if ($request->hasFile('file') && !$request->file('file')->isValid()) {
            if ($request->file('file')->getError() === UPLOAD_ERR_INI_SIZE) {
                return response()->json([
                    'error' => 'Arquivo maior do que o servidor aceita.',
                    'code'  => 'UPLOAD_TOO_LARGE',
                ], 422);
            }
        }

        $request->validate([
            'file'    => 'required|file|max:' . (MediaItem::MAX_SIZE_BYTES / 1024),
            'caption' => 'sometimes|nullable|string|max:500',
        ]);

        $file = $request->file('file');
        $mime = $file->getMimeType();

        if (!in_array($mime, MediaItem::ALLOWED_MIMES, true)) {
            return response()->json([
                'error' => 'Tipo de arquivo não aceito. Envie JPG, PNG, WEBP ou MP4.',
                'code'  => 'INVALID_MIME',
            ], 422);
        }

        try {
            if (str_starts_with($mime, 'image/')) {
                $normalizer = app(\App\Services\ImageNormalizer::class);
                $file = $normalizer->normalize($file);
                $mime = 'image/jpeg';
                $extension = 'jpg';
            } else {
                $extension = match ($mime) {
                    'video/mp4' => 'mp4', 'video/quicktime' => 'mov', default => 'bin',
                };
            }

            $filename = Str::uuid() . '.' . $extension;
            $directory = "disbursements/{$disbursement->id}";

            Storage::disk('private')->putFileAs($directory, $file, $filename);

            MediaItem::create([
                'owner_type'  => MediaItem::OWNER_DISBURSEMENT,
                'owner_id'    => $disbursement->id,
                'path'        => "{$directory}/{$filename}",
                'mime_type'   => $mime,
                'size_bytes'  => $file->getSize(),
                'caption'     => $request->input('caption'),
                'status'      => 'pending',
            ]);

            return response()->json([
                'message' => 'Obrigado! A imagem passará por revisão antes de aparecer para os doadores.',
            ], 201);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Erro ao salvar prova de disbursement: ' . $e->getMessage());
            return response()->json([
                'error' => 'Ocorreu um erro ao processar o arquivo.',
                'code'  => 'MEDIA_STORE_FAILED',
            ], 500);
        }
    }

    /**
     * POST /disbursements/{id}/reimbursement  (T4-R08)
     * Body: benefactor_tenant_id, amount, cap
     *
     * Não há recompensa financeira avulsa por resgate. O que existe é o
     * ressarcimento de custo operacional ao benfeitor, autorizado pela
     * família e limitado por um TETO.
     *
     * O teto é conferido no model (`reimbursementWithinCap`) para que
     * qualquer caminho que grave o valor passe pela mesma regra.
     */
    public function authorizeReimbursement(Request $request, $disbursementId)
    {
        $disbursement = Disbursement::find($disbursementId);

        if (!$disbursement) {
            return response()->json(['error' => 'Repasse não encontrado.'], 404);
        }

        $space = Space::find($disbursement->cause_space_id);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'finance.manage');

        $validated = $request->validate([
            'benefactor_tenant_id' => 'required|integer',
            'amount'               => 'required|numeric|min:0',
            'cap'                  => 'required|numeric|min:0',
        ]);

        $disbursement->fill([
            'benefactor_tenant_id' => $validated['benefactor_tenant_id'],
            'reimbursement_amount' => $validated['amount'],
            'reimbursement_cap'    => $validated['cap'],
        ]);

        if (!$disbursement->reimbursementWithinCap()) {
            return response()->json([
                'error' => 'O valor do ressarcimento ultrapassa o teto autorizado.',
                'code'  => 'ABOVE_CAP',
                'cap'   => $validated['cap'],
            ], 422);
        }

        $disbursement->reimbursement_authorized = true;
        $disbursement->save();

        return response()->json([
            'message'      => 'Ressarcimento autorizado.',
            'disbursement' => $this->present($disbursement->fresh()),
        ]);
    }

    /** Forma única de apresentação, para os endpoints não divergirem. */
    private function present(Disbursement $d): array
    {
        return [
            'id'           => $d->id,
            'kind'         => $d->kind,
            'description'  => $d->description,
            'amount'       => $d->amount,
            'status'       => $d->status,
            'next_states'  => Disbursement::TRANSITIONS[$d->status] ?? [],
            'approved_at'  => $d->approved_at,
            'sent_at'      => $d->sent_at,
            'confirmed_at' => $d->confirmed_at,
            'proof_factor' => $d->proof_factor,
            'reimbursement' => [
                'authorized' => $d->reimbursement_authorized,
                'amount'     => $d->reimbursement_amount,
                'cap'        => $d->reimbursement_cap,
            ],
        ];
    }
}
