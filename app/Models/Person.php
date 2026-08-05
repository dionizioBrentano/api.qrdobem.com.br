<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Person — a pessoa natural por trás das contas. Fundação F10 do
 * PLANO_TRILHAS_2026-08.md (TX-R02, TX-R03, TX-R04).
 *
 * Um CPF, um Person, N Tenants (contas com e-mails diferentes). É o que
 * permite mostrar "minhas contas e vínculos" num painel só e trocar de
 * conta sem logout.
 *
 * REGRA DE SEGURANÇA — não contornar:
 * CPF não é segredo. Este model NÃO deve ser usado para responder
 * "quais vínculos tem o CPF X?" a partir de um CPF digitado. A ligação
 * conta → pessoa acontece porque a conta comprovou a posse do CPF dentro
 * dela mesma (Gate 1). Qualquer consulta parte do tenant autenticado,
 * nunca de um CPF informado. Ver §3.F10 do plano.
 *
 * O CPF fica cifrado em `cpf_encrypted`; a busca usa o blind index
 * `cpf_hash` gerado por CpfIdentityService.
 */
class Person extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'cpf_hash',
        'cpf_encrypted',
        'verified_at',
    ];

    protected $casts = [
        'cpf_encrypted' => 'encrypted',
        'verified_at'   => 'datetime',
    ];

    /**
     * Campos que nunca devem sair numa resposta JSON.
     * O CPF cifrado não tem por que trafegar: o painel mostra vínculos,
     * não documento.
     */
    protected $hidden = [
        'cpf_hash',
        'cpf_encrypted',
    ];

    /** Todas as contas desta pessoa. */
    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    /** CPF mascarado para exibição: 123.***.**9-00 → apenas confirmação visual. */
    public function maskedCpf(): string
    {
        $cpf = preg_replace('/\D/', '', (string) $this->cpf_encrypted);

        if (strlen($cpf) !== 11) {
            return '***';
        }

        return substr($cpf, 0, 3) . '.***.***-' . substr($cpf, 9, 2);
    }
}
