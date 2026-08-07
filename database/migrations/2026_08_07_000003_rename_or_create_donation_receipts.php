<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separação dos agregados de doação — trata a tabela física `donations`.
 *
 * Dois conceitos colidiam no nome `donations`:
 *   - LEGADO: comprovantes manuais ligados a necessidade (need) — model
 *     DonationReceipt, tabela alvo `donation_receipts`.
 *   - CHECKOUT: doação financeira a causa — model DonationCause, tabela
 *     `donation_causes` (as migrations 000007/000001/000002 já foram
 *     ajustadas para criar/alterar `donation_causes`).
 *
 * Esta migration é DEFENSIVA e cobre os três estados possíveis de um
 * ambiente, sem dropar dados e sem reexecutar create que quebra:
 *
 *   1. Ambiente limpo (sem `donations`): 000007 já criou `donation_causes`.
 *      Aqui só materializamos `donation_receipts` (schema legado conhecido)
 *      para o model DonationReceipt ter tabela.
 *
 *   2. Produção com o LEGADO em `donations` (tem need_id/donor_unique_code):
 *      renomeia `donations` → `donation_receipts`, preservando os dados.
 *
 *   3. Ambiente que JÁ havia rodado o checkout antigo em `donations` (tem
 *      cause_space_id) antes do ajuste de nome: renomeia `donations` →
 *      `donation_causes`, para o código novo achar a tabela.
 *
 * A distinção é feita pelas COLUNAS, não por adivinhação: é o único jeito
 * seguro de saber o que aquela `donations` física realmente é.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('donations')) {
            $isLegacyReceipts = Schema::hasColumn('donations', 'need_id')
                || Schema::hasColumn('donations', 'donor_unique_code');
            $isCauseCheckout = Schema::hasColumn('donations', 'cause_space_id');

            if ($isLegacyReceipts && !Schema::hasTable('donation_receipts')) {
                Schema::rename('donations', 'donation_receipts');
            } elseif ($isCauseCheckout && !Schema::hasTable('donation_causes')) {
                Schema::rename('donations', 'donation_causes');
            }
        }

        // Garante a tabela de recibos legada mesmo sem legado a renomear
        // (ambiente limpo), com o schema de referência dado no domínio.
        if (!Schema::hasTable('donation_receipts')) {
            Schema::create('donation_receipts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('need_id')->nullable();
                $table->string('donor_unique_code')->nullable();
                $table->string('donor_name')->nullable();
                $table->string('donor_contact')->nullable();
                $table->text('description')->nullable();
                $table->enum('receipt_type', ['paper', 'gov_br_signature'])->nullable();
                $table->string('receipt_file_path')->nullable();
                $table->timestamp('donated_at')->nullable();
                $table->foreignId('registered_by_tenant_id')->nullable();
                $table->softDeletes();
                $table->timestamps();

                $table->index('need_id');
                $table->index('donor_unique_code');
            });
        }
    }

    public function down(): void
    {
        // Best-effort e sem destruir dados: se o legado foi renomeado, desfaz
        // o rename. Ambientes que apenas criaram a tabela vazia também voltam
        // a `donations` — aceitável, pois não há perda.
        if (Schema::hasTable('donation_receipts') && !Schema::hasTable('donations')) {
            Schema::rename('donation_receipts', 'donations');
        }
    }
};
