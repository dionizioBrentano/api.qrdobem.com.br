<?php

namespace App\Console\Commands;

use App\Models\Entity;
use App\Models\Organization;
use App\Models\Space;
use App\Models\SpaceMember;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * spaces:backfill — cria um Space para cada Organization existente e liga
 * as entidades ao espaço correspondente.
 *
 * Fase 0, entrega 0.1 do PLANO_TRILHAS_2026-08.md.
 *
 * Contexto: o sistema já em produção tem entidades penduradas em
 * organizations. A migration criou `entities.space_id` como nullable
 * justamente para que este comando preencha sem downtime — nenhuma
 * consulta atual usa a coluna ainda (a troca acontece na entrega 0.5).
 *
 * Idempotente: rodar duas vezes não duplica espaço nem membro. A ligação
 * organization → space é registrada em `spaces.organization_id`, e é por
 * ela que o comando reconhece o que já processou.
 *
 * Tipo atribuído: todas as organizações existentes viram espaço `family`.
 * Motivo: hoje o produto em produção é o QR de pessoa/pet/objeto, que é a
 * Trilha 1. Reclassificar depois é um UPDATE de uma coluna; classificar
 * errado agora e espalhar a suposição pelo código é que sairia caro.
 *
 * USO (no servidor, via SSH, dentro de ~/api.qrdobem.com.br):
 *   php artisan spaces:backfill --dry-run   ← mostra o que faria
 *   php artisan spaces:backfill             ← executa
 */
class BackfillSpaces extends Command
{
    protected $signature = 'spaces:backfill {--dry-run : Apenas relata, sem gravar}';

    protected $description = 'Cria Spaces a partir das Organizations existentes e liga as entidades';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('MODO DRY-RUN: nada será gravado.');
        }

        $organizations = Organization::orderBy('id')->get();

        if ($organizations->isEmpty()) {
            $this->info('Nenhuma organização encontrada. Nada a fazer.');
            return self::SUCCESS;
        }

        $createdSpaces = 0;
        $linkedEntities = 0;
        $createdMembers = 0;

        foreach ($organizations as $organization) {
            $space = Space::where('organization_id', $organization->id)->first();

            if ($space) {
                $this->line("Organização #{$organization->id} já tem o espaço #{$space->id}.");
            } else {
                $this->line("Organização #{$organization->id} ({$organization->name}) → criar espaço.");

                if (!$dryRun) {
                    $space = DB::transaction(function () use ($organization) {
                        $newSpace = Space::create([
                            'owner_tenant_id' => $organization->owner_tenant_id,
                            'organization_id' => $organization->id,
                            'type'            => Space::TYPE_FAMILY,
                            'name'            => $organization->name,
                            'slug'            => Space::generateSlug($organization->name),
                            'status'          => 'active',
                        ]);

                        // O dono da organização entra como owner do espaço,
                        // com o convite já aceito — ele não precisa aceitar
                        // convite para o próprio espaço.
                        SpaceMember::create([
                            'space_id'    => $newSpace->id,
                            'tenant_id'   => $organization->owner_tenant_id,
                            'role'        => SpaceMember::ROLE_OWNER,
                            'accepted_at' => now(),
                        ]);

                        return $newSpace;
                    });

                    $createdSpaces++;
                    $createdMembers++;
                }
            }

            // Sem espaço (só acontece em dry-run) não há o que ligar.
            if (!$space) {
                continue;
            }

            $pending = Entity::withTrashed()
                ->where('organization_id', $organization->id)
                ->whereNull('space_id');

            $count = $pending->count();

            if ($count === 0) {
                continue;
            }

            $this->line("  → {$count} entidade(s) para ligar ao espaço #{$space->id}.");

            if (!$dryRun) {
                $linkedEntities += $pending->update(['space_id' => $space->id]);
            }
        }

        // Conferência final: entidade sem espaço depois do backfill é sinal
        // de organização ausente. Relatar é melhor que deixar passar calado.
        $orphans = Entity::withTrashed()->whereNull('space_id')->count();

        $this->newLine();
        $this->info('Resumo:');
        $this->line("  Espaços criados:      {$createdSpaces}");
        $this->line("  Membros owner criados:{$createdMembers}");
        $this->line("  Entidades ligadas:    {$linkedEntities}");
        $this->line("  Entidades sem espaço: {$orphans}");

        if ($orphans > 0 && !$dryRun) {
            $this->warn('Há entidades sem espaço. Verifique se elas têm organization_id preenchido.');
        }

        return self::SUCCESS;
    }
}
