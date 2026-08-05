<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;

/**
 * Beneficiary — quem recebe o repasse. Fase 4, T4-R05, T4-R06, T4-R09.
 *
 * A URL ÚNICA É OBRIGATÓRIA (requisito)
 * O beneficiário usa `unique_code` para solicitar o que precisa, comprovar
 * o recebimento e registrar a prova social. Não há caminho alternativo:
 * é isso que amarra pedido, entrega e comprovação à mesma pessoa.
 *
 * FATORES DE CONTRAPROVA (F9)
 * `accepted_proof_factors` guarda o que vale para ESTE beneficiário:
 * `password`, `facial`, `tutor`. Ter mais de um evita o repasse travar
 * quando a senha se perde — e quem está fora do mundo digital opera pelo
 * tutor (T4-R09), com a confirmação registrada como tal.
 */
class Beneficiary extends Model
{
    use SoftDeletes;

    public const FACTOR_PASSWORD = 'password';
    public const FACTOR_FACIAL   = 'facial';
    public const FACTOR_TUTOR    = 'tutor';

    public const FACTORS = [
        self::FACTOR_PASSWORD,
        self::FACTOR_FACIAL,
        self::FACTOR_TUTOR,
    ];

    protected $fillable = [
        'space_id',
        'unique_code',
        'name',
        'encrypted_document',
        'phone',
        'city',
        'state',
        'proof_password_hash',
        'accepted_proof_factors',
        'tutor_tenant_id',
        'encrypted_bank_info',
        'status',
    ];

    protected $casts = [
        'encrypted_document'     => 'encrypted',
        'encrypted_bank_info'    => 'encrypted',
        'accepted_proof_factors' => 'array',
    ];

    /**
     * Nada disso pode sair em resposta JSON. `proof_password_hash` é
     * credencial; documento e dados bancários são o alvo óbvio de quem
     * quiser desviar repasse.
     */
    protected $hidden = [
        'proof_password_hash',
        'encrypted_document',
        'encrypted_bank_info',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /** Quem opera o sistema pelo beneficiário (T4-R09). */
    public function tutor(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tutor_tenant_id');
    }

    public function needs(): HasMany
    {
        return $this->hasMany(BeneficiaryNeed::class);
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(Disbursement::class);
    }

    /** Fatores válidos. Sem configuração explícita, vale a senha. */
    public function factors(): array
    {
        $factors = $this->accepted_proof_factors ?: [self::FACTOR_PASSWORD];

        // Tutor vinculado habilita o fator automaticamente: cadastrar o
        // tutor e depois esquecer de liberar o fator deixaria o
        // beneficiário sem caminho de confirmação.
        if ($this->tutor_tenant_id && !in_array(self::FACTOR_TUTOR, $factors, true)) {
            $factors[] = self::FACTOR_TUTOR;
        }

        return $factors;
    }

    public function acceptsFactor(string $factor): bool
    {
        return in_array($factor, $this->factors(), true);
    }

    /**
     * Confere a senha pessoal da contraprova.
     * `Hash::check` já é resistente a ataque de tempo.
     */
    public function checkProofPassword(string $password): bool
    {
        if (!$this->proof_password_hash) {
            return false;
        }

        return Hash::check($password, $this->proof_password_hash);
    }

    public function setProofPassword(string $password): void
    {
        $this->update(['proof_password_hash' => Hash::make($password)]);
    }
}
