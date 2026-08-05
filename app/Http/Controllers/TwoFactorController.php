<?php

namespace App\Http\Controllers;

use App\Models\TenantTwoFactor;
use App\Services\TotpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * TwoFactorController — 2FA opcional por aplicativo autenticador.
 * Fase 1, entrega 1.5 do PLANO_TRILHAS_2026-08.md (T1-R05).
 *
 * FLUXO EM DUAS ETAPAS, DE PROPÓSITO
 *   1. `setup`   → gera o segredo e devolve o otpauth:// para o QR.
 *                  Ainda NÃO ativa nada.
 *   2. `confirm` → o usuário digita um código do app. Só aí o 2FA passa a
 *                  valer, e os códigos de recuperação são exibidos.
 *
 * Sem a etapa 2, quem fecha a tela antes de escanear fica trancado fora da
 * conta com um segredo que não guardou.
 *
 * ESCOPO HONESTO DESTA ENTREGA
 * O 2FA fica registrado e verificável por `POST /2fa/verify`. Ele NÃO é
 * exigido no login: o login é do Firebase, e interceptá-lo exigiria mudar
 * o fluxo de autenticação inteiro. A exigência entra nas operações
 * sensíveis do próprio sistema (repasse, alteração de permissão, revelação
 * de dado), que é onde ele protege de fato — o login já tem o fator do
 * Firebase.
 */
class TwoFactorController extends Controller
{
    public function __construct(private TotpService $totp)
    {
    }

    /** GET /2fa/status */
    public function status(Request $request)
    {
        $record = TenantTwoFactor::where('tenant_id', $request->tenant->id)->first();

        return response()->json([
            'enabled'          => $record?->isConfirmed() ?? false,
            'pending_setup'    => $record !== null && !$record->isConfirmed(),
            'recovery_codes_left' => $record?->isConfirmed()
                ? count($record->recovery_codes ?? [])
                : 0,
            'last_used_at'     => $record?->last_used_at,
        ]);
    }

    /**
     * POST /2fa/setup
     * Gera segredo novo e devolve o otpauth:// para montar o QR.
     */
    public function setup(Request $request)
    {
        $tenant = $request->tenant;

        $existing = TenantTwoFactor::where('tenant_id', $tenant->id)->first();

        // Já ativo: não regenera. Regenerar em silêncio invalidaria o app
        // do usuário sem ele pedir.
        if ($existing && $existing->isConfirmed()) {
            return response()->json([
                'error' => 'A verificação em duas etapas já está ativa. Desative antes de configurar de novo.',
                'code'  => 'ALREADY_ENABLED',
            ], 422);
        }

        $secret = $this->totp->generateSecret();
        $account = $tenant->email ?: ('conta-' . $tenant->id);

        if ($existing) {
            $existing->update(['secret' => $secret, 'confirmed_at' => null]);
        } else {
            TenantTwoFactor::create([
                'tenant_id' => $tenant->id,
                'secret'    => $secret,
            ]);
        }

        return response()->json([
            // Exibidos UMA vez, na tela de configuração.
            'secret'           => $secret,
            'provisioning_uri' => $this->totp->provisioningUri($secret, $account),
            'message'          => 'Escaneie no app autenticador e confirme com um código para ativar.',
        ]);
    }

    /**
     * POST /2fa/confirm  { code }
     * Ativa o 2FA e devolve os códigos de recuperação.
     */
    public function confirm(Request $request)
    {
        $validated = $request->validate(['code' => 'required|string']);

        $record = TenantTwoFactor::where('tenant_id', $request->tenant->id)->first();

        if (!$record) {
            return response()->json([
                'error' => 'Nenhuma configuração de 2FA iniciada.',
                'code'  => 'NO_SETUP',
            ], 422);
        }

        if ($record->isConfirmed()) {
            return response()->json(['message' => 'A verificação em duas etapas já está ativa.']);
        }

        if (!$this->totp->verify($record->secret, $validated['code'])) {
            return response()->json([
                'error' => 'Código inválido. Confira o horário do celular e tente de novo.',
                'code'  => 'INVALID_CODE',
            ], 422);
        }

        $recoveryCodes = $this->totp->generateRecoveryCodes();

        $record->update([
            'confirmed_at'   => now(),
            // Guardamos só o hash: um vazamento do banco não pode entregar
            // os códigos de recuperação prontos para uso.
            'recovery_codes' => array_map(fn ($c) => Hash::make($c), $recoveryCodes),
        ]);

        return response()->json([
            'message'        => 'Verificação em duas etapas ativada.',
            // Única vez que aparecem em claro.
            'recovery_codes' => $recoveryCodes,
            'warning'        => 'Guarde estes códigos. Eles não serão exibidos de novo.',
        ]);
    }

    /**
     * POST /2fa/verify  { code }
     * Verifica um código do app OU um código de recuperação.
     * Usado pelas operações sensíveis, não pelo login.
     */
    public function verify(Request $request)
    {
        $validated = $request->validate(['code' => 'required|string']);

        $record = TenantTwoFactor::where('tenant_id', $request->tenant->id)->first();

        if (!$record || !$record->isConfirmed()) {
            return response()->json([
                'error' => 'Verificação em duas etapas não está ativa nesta conta.',
                'code'  => 'NOT_ENABLED',
            ], 422);
        }

        $code = $validated['code'];

        if ($this->totp->verify($record->secret, $code)) {
            $record->update(['last_used_at' => now()]);
            return response()->json(['verified' => true, 'method' => 'totp']);
        }

        if ($record->consumeRecoveryCode($code)) {
            $record->update(['last_used_at' => now()]);
            return response()->json([
                'verified' => true,
                'method'   => 'recovery',
                'codes_left' => count($record->fresh()->recovery_codes ?? []),
            ]);
        }

        return response()->json([
            'error' => 'Código inválido.',
            'code'  => 'INVALID_CODE',
        ], 422);
    }

    /**
     * POST /2fa/disable  { code }
     * Exige código válido: desativar sem provar posse do fator permitiria
     * a quem roubasse a sessão remover a proteção.
     */
    public function disable(Request $request)
    {
        $validated = $request->validate(['code' => 'required|string']);

        $record = TenantTwoFactor::where('tenant_id', $request->tenant->id)->first();

        if (!$record) {
            return response()->json(['message' => 'Verificação em duas etapas já está desativada.']);
        }

        if ($record->isConfirmed()
            && !$this->totp->verify($record->secret, $validated['code'])
            && !$record->consumeRecoveryCode($validated['code'])) {
            return response()->json([
                'error' => 'Código inválido.',
                'code'  => 'INVALID_CODE',
            ], 422);
        }

        $record->delete();

        return response()->json(['message' => 'Verificação em duas etapas desativada.']);
    }
}
