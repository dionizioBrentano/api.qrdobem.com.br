<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

/**
 * TenantTwoFactor — segredo TOTP da conta. Fase 1, T1-R05.
 *
 * `confirmed_at` nulo significa configuração iniciada e não concluída: o
 * 2FA só passa a valer depois que o usuário digita um código válido. Sem
 * essa etapa, quem fecha a tela antes de salvar o segredo fica trancado
 * fora da própria conta.
 */
class TenantTwoFactor extends Model
{
    protected $table = 'tenant_two_factor';

    protected $fillable = [
        'tenant_id',
        'secret',
        'confirmed_at',
        'recovery_codes',
        'last_used_at',
    ];

    protected $casts = [
        'secret'         => 'encrypted',
        'recovery_codes' => 'encrypted:array',
        'confirmed_at'   => 'datetime',
        'last_used_at'   => 'datetime',
    ];

    /** O segredo nunca sai numa serialização automática. */
    protected $hidden = [
        'secret',
        'recovery_codes',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /**
     * Consome um código de recuperação: confere e remove.
     * Código de recuperação é de uso único — reaproveitar equivaleria a uma
     * senha fixa que nunca expira.
     */
    public function consumeRecoveryCode(string $code): bool
    {
        $codes = $this->recovery_codes ?? [];

        foreach ($codes as $index => $hashed) {
            if (Hash::check(strtoupper(trim($code)), $hashed)) {
                unset($codes[$index]);
                $this->update(['recovery_codes' => array_values($codes)]);
                return true;
            }
        }

        return false;
    }
}
