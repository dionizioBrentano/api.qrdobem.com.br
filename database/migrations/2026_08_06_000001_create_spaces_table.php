<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 0, entrega 0.1 — Espaço de Trilha (`spaces`).
 * Referência: PLANO_TRILHAS_2026-08.md, fundação F1.
 *
 * Generaliza a Organization atual. Cada conta pode ter um ou mais espaços
 * tipados (family, cause, company, donation), e é o espaço — não a
 * organização — que passa a ser a unidade de produto do sistema.
 *
 * A `organizations` NÃO é substituída nem alterada: ela continua sendo o
 * vínculo jurídico/fiscal (CNPJ, OSCIP). O espaço aponta para ela quando
 * esse vínculo existe, e vive sem ela quando a iniciativa é de pessoa
 * física (requisito T2-R01, cadastro sem CNPJ).
 *
 * `parent_space_id` atende o projeto guarda-chuva (T2-R02): uma OSCIP
 * apoiando grupos menores, com os grupos pendurados no espaço da OSCIP.
 *
 * `settings` (json) guarda white-label da Trilha 3 (T3-R02: logo, cores),
 * flags de módulo premium e tetos configuráveis, sem exigir migration a
 * cada parâmetro novo.
 *
 * Esta migration NÃO altera comportamento: apenas cria a tabela e a coluna
 * `entities.space_id` (nullable). O preenchimento é feito pelo comando
 * `php artisan spaces:backfill` (entrega 0.1), e a troca das consultas dos
 * controllers acontece na entrega 0.5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spaces', function (Blueprint $table) {
            $table->id();

            // Dono do espaço. É quem responde por ele — na Trilha 1, o
            // "fundador do grupo familiar" (T1-R03).
            $table->foreignId('owner_tenant_id')
                  ->constrained('tenants')
                  ->cascadeOnDelete();

            // Vínculo jurídico, quando existir. Nullable justamente para
            // permitir iniciativa autônoma de pessoa física (T2-R01).
            $table->foreignId('organization_id')
                  ->nullable()
                  ->constrained('organizations')
                  ->nullOnDelete();

            // Projeto guarda-chuva (T2-R02). Auto-relacionamento.
            $table->unsignedBigInteger('parent_space_id')->nullable();

            $table->enum('type', ['family', 'cause', 'company', 'donation']);

            $table->string('name');

            // Slug público do espaço. Unique porque pode virar URL.
            $table->string('slug')->unique();

            // White-label, flags e parâmetros. Ver F1 no plano.
            $table->json('settings')->nullable();

            $table->enum('status', ['active', 'suspended'])->default('active');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_tenant_id', 'type']);
            $table->index('parent_space_id');

            $table->foreign('parent_space_id')
                  ->references('id')->on('spaces')
                  ->nullOnDelete();
        });

        // Vínculo da entidade (QR Code) com o espaço.
        // Nullable nesta fase: o backfill preenche, e só depois de validado
        // é que a coluna passa a ser obrigatória (migration futura).
        Schema::table('entities', function (Blueprint $table) {
            $table->foreignId('space_id')
                  ->nullable()
                  ->after('organization_id')
                  ->constrained('spaces')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropForeign(['space_id']);
            $table->dropColumn('space_id');
        });

        Schema::table('spaces', function (Blueprint $table) {
            $table->dropForeign(['parent_space_id']);
        });

        Schema::dropIfExists('spaces');
    }
};
