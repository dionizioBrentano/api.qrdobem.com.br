<?php

namespace App\Services;

use App\Models\Medication;
use App\Models\MedicationConfirmation;
use App\Models\MedicationLeaflet;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MedicationLookupService — identifica o medicamento pelo código de barras.
 * Fase 6, T1-R09 e T1-R10 do PLANO_TRILHAS_2026-08.md.
 *
 * O PROCESSO, conforme decisão do proprietário (05/08/2026):
 *   1. EAN conhecido e confiável → responde do nosso banco, sem rede
 *   2. EAN desconhecido → busca na internet
 *   3. mostra o que achou e pergunta: "é este o produto que você comprou?"
 *   4. usuário confirma ou corrige
 *   5. com N confirmações independentes, o registro vira confiável
 *
 * POR QUE ISSO É MELHOR QUE INTEGRAR COM BASE OFICIAL
 * A base oficial precisa estar certa para todo mundo antes do primeiro uso.
 * Esta cresce sozinha, validada por quem tem a caixa na mão — a única
 * pessoa capaz de dizer se o produto está certo. E a partir de certo
 * volume, a rede quase não é mais acionada.
 *
 * MECANISMO DE BUSCA
 * Configurável por env (`MEDICATION_LOOKUP_URL`). Sem configuração, o
 * serviço devolve um registro vazio para o usuário preencher à mão — em
 * vez de quebrar. Cadastro manual assistido é um caminho legítimo; falhar
 * porque a busca externa não está configurada, não.
 */
class MedicationLookupService
{
    /**
     * Resolve um EAN. Sempre devolve um Medication (criando se necessário)
     * mais a informação de se ainda precisa de confirmação do usuário.
     *
     * @return array{medication: Medication, needs_confirmation: bool, from_cache: bool}
     */
    public function resolve(string $ean): array
    {
        $ean = preg_replace('/\D/', '', $ean) ?? '';

        $existing = Medication::where('ean', $ean)->first();

        // Já conhecido: responde do banco. A rede só é acionada na estreia
        // de cada código.
        if ($existing) {
            return [
                'medication'         => $existing,
                'needs_confirmation' => $existing->needsConfirmation(),
                'from_cache'         => true,
            ];
        }

        $found = $this->searchOnline($ean);

        $medication = Medication::create([
            'ean'               => $ean,
            'name'              => $found['name'] ?? 'Medicamento não identificado',
            'presentation'      => $found['presentation'] ?? null,
            'laboratory'        => $found['laboratory'] ?? null,
            'active_ingredient' => $found['active_ingredient'] ?? null,
            'registry_number'   => $found['registry_number'] ?? null,
            'source'            => $found['source'] ?? 'manual',
            'status'            => Medication::STATUS_PENDING,
        ]);

        return [
            'medication'         => $medication,
            // Sempre pergunta na primeira vez, mesmo com resultado da busca:
            // é justamente a confirmação de quem tem a caixa que dá valor
            // ao dado.
            'needs_confirmation' => true,
            'from_cache'         => false,
        ];
    }

    /**
     * Registra o voto do usuário sobre o medicamento.
     *
     * `updateOrCreate` porque o unique (medication_id, tenant_id) garante
     * um voto por pessoa: se ela mudar de ideia, o voto é substituído, não
     * somado.
     */
    public function confirm(Medication $medication, Tenant $tenant, bool $isCorrect, array $correction = []): Medication
    {
        MedicationConfirmation::updateOrCreate(
            [
                'medication_id' => $medication->id,
                'tenant_id'     => $tenant->id,
            ],
            [
                'action'            => $isCorrect
                    ? MedicationConfirmation::ACTION_CONFIRMED
                    : MedicationConfirmation::ACTION_CORRECTED,
                'corrected_payload' => $isCorrect ? null : $correction,
            ]
        );

        // Correção com dados: aplica o que o usuário disse que é. Ele tem a
        // caixa na mão; nós temos um resultado de busca.
        if (!$isCorrect && !empty($correction['name'])) {
            $medication->update([
                'name'         => $correction['name'],
                'presentation' => $correction['presentation'] ?? $medication->presentation,
                'laboratory'   => $correction['laboratory'] ?? $medication->laboratory,
                'source'       => 'user_correction',
            ]);
        }

        $medication->refreshTrust();

        return $medication->fresh();
    }

    /**
     * Busca a bula e guarda em cache.
     * Devolve null quando não há fonte configurada — e o sistema segue
     * funcionando com o intervalo informado pelo usuário.
     */
    public function fetchLeaflet(Medication $medication): ?MedicationLeaflet
    {
        $existing = MedicationLeaflet::where('medication_id', $medication->id)->first();

        if ($existing) {
            return $existing;
        }

        $url = env('MEDICATION_LEAFLET_URL');

        if (!$url) {
            return null;
        }

        try {
            $response = Http::timeout(10)->connectTimeout(5)->get($url, [
                'name'     => $medication->name,
                'registry' => $medication->registry_number,
            ]);

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();

            return MedicationLeaflet::create([
                'medication_id'    => $medication->id,
                'source_url'       => $data['url'] ?? null,
                'content'          => $data['content'] ?? null,
                'posology_excerpt' => $data['posology'] ?? null,
                'fetched_at'       => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('MedicationLookupService: falha ao buscar bula', [
                'medication_id' => $medication->id,
                'error'         => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Busca o produto na internet a partir do EAN.
     *
     * O endpoint é configurável porque a escolha do provedor é decisão de
     * implementação (custo, limite, cobertura) e não deve estar cravada no
     * código. Sem configuração, devolve vazio — o usuário preenche à mão,
     * que é caminho legítimo, e o dado dele alimenta a base do mesmo jeito.
     */
    private function searchOnline(string $ean): array
    {
        $url = env('MEDICATION_LOOKUP_URL');

        if (!$url) {
            return ['source' => 'manual'];
        }

        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->get($url, ['ean' => $ean]);

            if (!$response->successful()) {
                return ['source' => 'manual'];
            }

            $data = $response->json();

            return [
                'name'              => $data['name'] ?? null,
                'presentation'      => $data['presentation'] ?? null,
                'laboratory'        => $data['laboratory'] ?? null,
                'active_ingredient' => $data['active_ingredient'] ?? null,
                'registry_number'   => $data['registry_number'] ?? null,
                'source'            => 'lookup',
            ];
        } catch (\Throwable $e) {
            // Falha de rede não pode impedir o cadastro: o usuário preenche
            // à mão e segue a vida.
            Log::warning('MedicationLookupService: busca falhou', [
                'ean'   => $ean,
                'error' => $e->getMessage(),
            ]);

            return ['source' => 'manual'];
        }
    }
}
