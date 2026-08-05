<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\CpfIdentityService;
use App\Services\CpfValidator;
use Illuminate\Console\Command;

/**
 * people:backfill — agrupa as contas existentes por CPF, criando o Person
 * correspondente e preenchendo `tenants.person_id` e `tenants.cpf_hash`.
 *
 * Fase 0, entrega 0.8 do PLANO_TRILHAS_2026-08.md (TX-R03).
 *
 * É este comando que faz as 3 contas do proprietário (3 e-mails, 1 CPF)
 * aparecerem agrupadas no painel.
 *
 * Idempotente: contas já ligadas são puladas. Rodar de novo depois de novos
 * cadastros apenas processa o que faltava.
 *
 * CPF INVÁLIDO: a conta é relatada e pulada, nunca ligada a um Person
 * errado. Há contas antigas cadastradas antes da validação de dígito
 * verificador entrar no ProfileController — juntar duas pessoas debaixo de
 * um CPF inválido seria pior do que deixá-las separadas.
 *
 * USO (no servidor, via SSH, dentro de ~/api.qrdobem.com.br):
 *   php artisan people:backfill --dry-run   ← mostra o que faria
 *   php artisan people:backfill             ← executa
 */
class BackfillPeople extends Command
{
    protected $signature = 'people:backfill {--dry-run : Apenas relata, sem gravar}';

    protected $description = 'Agrupa contas por CPF criando os registros de Person';

    public function handle(CpfIdentityService $identity, CpfValidator $validator): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('MODO DRY-RUN: nada será gravado.');
        }

        $tenants = Tenant::withTrashed()
            ->whereNotNull('cpf')
            ->whereNull('person_id')
            ->orderBy('id')
            ->get();

        if ($tenants->isEmpty()) {
            $this->info('Nenhuma conta pendente de vínculo. Nada a fazer.');
            return self::SUCCESS;
        }

        $linked = 0;
        $invalid = 0;
        $groups = [];

        foreach ($tenants as $tenant) {
            $cpf = $identity->normalize((string) $tenant->cpf);

            if (!$validator->isValid($cpf)) {
                $this->warn("Conta #{$tenant->id} ({$tenant->email}): CPF inválido — pulada.");
                $invalid++;
                continue;
            }

            $hash = $identity->hash($cpf);
            $groups[$hash][] = $tenant;

            $this->line("Conta #{$tenant->id} ({$tenant->email}) → pessoa {$this->shortHash($hash)}");

            if (!$dryRun) {
                $identity->linkTenantToPerson($tenant, $cpf);
                $linked++;
            }
        }

        // O valor do comando está aqui: mostrar quais contas são a mesma
        // pessoa. Sem isso, o operador não tem como conferir o resultado.
        $shared = array_filter($groups, fn ($list) => count($list) > 1);

        $this->newLine();

        if (!empty($shared)) {
            $this->info('Contas agrupadas sob a mesma pessoa:');
            foreach ($shared as $hash => $list) {
                $emails = collect($list)->map(fn ($t) => "#{$t->id} {$t->email}")->implode(', ');
                $this->line("  {$this->shortHash($hash)}: {$emails}");
            }
            $this->newLine();
        }

        $this->info('Resumo:');
        $this->line('  Contas analisadas: ' . $tenants->count());
        $this->line("  Contas vinculadas: {$linked}");
        $this->line("  CPFs inválidos:    {$invalid}");
        $this->line('  Pessoas distintas: ' . count($groups));

        return self::SUCCESS;
    }

    /**
     * Prefixo do hash, apenas para o operador distinguir grupos no relatório.
     * O hash completo não é impresso: ele é material de ataque por dicionário
     * contra CPF, que tem espaço de busca pequeno.
     */
    private function shortHash(string $hash): string
    {
        return substr($hash, 0, 8);
    }
}
