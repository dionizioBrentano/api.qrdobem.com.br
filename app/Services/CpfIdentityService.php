<?php

namespace App\Services;

use App\Models\Person;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * CpfIdentityService — liga uma conta (Tenant) à pessoa natural (Person)
 * a partir do CPF, usando blind index.
 *
 * Fundação F10 do PLANO_TRILHAS_2026-08.md (TX-R02, TX-R03).
 *
 * POR QUE BLIND INDEX
 * O cast `encrypted` do Laravel não é determinístico: cifrar o mesmo CPF
 * duas vezes produz textos diferentes, logo `where('cpf_encrypted', ...)`
 * nunca encontra nada. O blind index é um HMAC-SHA256 determinístico do
 * CPF, indexado, que permite localizar sem manter o dado legível.
 *
 * A chave do HMAC deriva da APP_KEY. Consequência operacional que precisa
 * estar registrada: **trocar a APP_KEY invalida todos os hashes** e exige
 * recalcular a coluna. A mesma dependência já existe hoje para todo campo
 * com cast `encrypted` no sistema (entities, tenant_documents), então isso
 * não introduz risco novo — apenas o estende.
 *
 * FRONTEIRA DE SEGURANÇA
 * Este serviço resolve CPF → Person. Ele NÃO deve ser exposto por nenhum
 * endpoint que receba CPF do cliente e devolva vínculos: CPF não é segredo
 * e isso viraria consulta de vínculo social de terceiros. O uso legítimo é
 * um só — a própria conta declarando o próprio CPF no Gate 1.
 */
class CpfIdentityService
{
    public function __construct(private CpfValidator $validator)
    {
    }

    /** Mantém apenas os dígitos. */
    public function normalize(string $cpf): string
    {
        return preg_replace('/\D/', '', $cpf) ?? '';
    }

    /**
     * Blind index do CPF.
     * Prefixo de domínio ('cpf:') evita que o mesmo valor gere o mesmo hash
     * caso o padrão seja reaproveitado para outro documento no futuro.
     */
    public function hash(string $cpf): string
    {
        $normalized = $this->normalize($cpf);

        return hash_hmac('sha256', 'cpf:' . $normalized, $this->key());
    }

    /**
     * Localiza a pessoa dona deste CPF, ou cria uma.
     * Idempotente: chamar duas vezes com o mesmo CPF devolve o mesmo Person.
     *
     * @throws \InvalidArgumentException se o CPF for inválido
     */
    public function findOrCreatePerson(string $cpf): Person
    {
        $normalized = $this->normalize($cpf);

        if (!$this->validator->isValid($normalized)) {
            throw new \InvalidArgumentException('CPF inválido.');
        }

        $hash = $this->hash($normalized);

        $existing = Person::where('cpf_hash', $hash)->first();

        if ($existing) {
            return $existing;
        }

        return Person::create([
            'cpf_hash'      => $hash,
            'cpf_encrypted' => $normalized,
        ]);
    }

    /**
     * Liga a conta à pessoa dona do CPF. Chamado no Gate 1, quando a conta
     * comprova a posse do próprio CPF (entrega 0.9).
     *
     * Usa transação porque grava em duas tabelas: se a atualização do tenant
     * falhar, o Person recém-criado não deve ficar órfão.
     *
     * @throws \InvalidArgumentException se o CPF for inválido
     */
    public function linkTenantToPerson(Tenant $tenant, string $cpf): Person
    {
        $normalized = $this->normalize($cpf);

        return DB::transaction(function () use ($tenant, $normalized) {
            $person = $this->findOrCreatePerson($normalized);

            $tenant->update([
                'person_id' => $person->id,
                'cpf_hash'  => $this->hash($normalized),
            ]);

            // A pessoa é considerada verificada quando ao menos uma de suas
            // contas concluiu o Gate 1 (e-mail por OTP + CPF + telefone).
            if ($person->verified_at === null && $tenant->email_verified_at !== null) {
                $person->update(['verified_at' => now()]);
            }

            return $person;
        });
    }

    /**
     * Contas da mesma pessoa que o tenant informado.
     * Parte SEMPRE do tenant autenticado — nunca de um CPF recebido do
     * cliente. Ver a fronteira de segurança no cabeçalho.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Tenant>
     */
    public function accountsOf(Tenant $tenant)
    {
        if (!$tenant->person_id) {
            return Tenant::where('id', $tenant->id)->get();
        }

        return Tenant::where('person_id', $tenant->person_id)
            ->orderBy('id')
            ->get();
    }

    /**
     * Chave do HMAC, derivada da APP_KEY.
     * A APP_KEY vem em base64 no .env do Laravel; decodificamos para usar os
     * bytes reais.
     */
    private function key(): string
    {
        $appKey = (string) config('app.key');

        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);

            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $appKey;
    }
}
