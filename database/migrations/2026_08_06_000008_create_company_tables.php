<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5 — Empresas: API aberta, white-label e casos B2B.
 * Referência: PLANO_TRILHAS_2026-08.md, T3-R01 a T3-R08.
 *
 * A DECISÃO DE ARQUITETURA DESTA FASE
 * Os três casos B2B do requisito — certificação de EPI, liberação de
 * material para terceirizado e portaria de condomínio — são o MESMO
 * primitivo: "evento de confirmação autenticada vinculado a um QR"
 * (quem, o quê, quando, onde, com qual prova).
 *
 * Codificar como três módulos triplicaria a manutenção e ainda deixaria de
 * fora o quarto caso que aparecer. Por isso existe UM motor genérico
 * (`confirmation_templates` + `confirmation_events`) com templates por caso
 * de uso. Um caso novo é uma linha de configuração, não uma sprint.
 *
 * FORA DE ESCOPO (T3-R08): faturamento e emissão de nota fiscal. O sistema
 * apenas EXPORTA os dados de consumo para a contabilidade externa.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- API aberta (T3-R01) ---
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();

            $table->foreignId('space_id')
                  ->constrained('spaces')
                  ->cascadeOnDelete();

            $table->string('name');

            // Identificador público da chave, enviado no header. Não é
            // segredo — serve para localizar o registro sem varrer hashes.
            $table->string('key_id', 32)->unique();

            // Só o HASH do segredo. Um vazamento do banco não pode entregar
            // credencial pronta para uso, do mesmo modo que senha.
            $table->string('secret_hash');

            // Escopos concedidos: ["entities.read","entities.write"].
            $table->json('scopes')->nullable();

            // Limite por minuto. Por parceiro, e não global: um integrador
            // mal configurado não pode derrubar a API para os outros.
            $table->unsignedInteger('rate_limit_per_minute')->default(60);

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index(['space_id', 'revoked_at']);
        });

        // --- Motor de confirmação (T3-R05, T3-R06, T3-R07) ---
        Schema::create('confirmation_templates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('space_id')
                  ->constrained('spaces')
                  ->cascadeOnDelete();

            $table->string('name');                       // "Entrega de EPI"
            $table->string('slug', 60);                   // "epi"

            // Caso de uso, para relatório e rótulo: epi | logistics |
            // concierge | custom. Não muda a lógica — muda a etiqueta.
            $table->string('use_case', 30)->default('custom');

            // Campos que o confirmador precisa preencher, em JSON:
            // [{"key":"ca_number","label":"Nº do CA","type":"text","required":true}]
            $table->json('fields')->nullable();

            // Exige senha do confirmador? No caso do EPI, sim: o requisito
            // fala em "leitura do QR + senha do funcionário".
            $table->boolean('requires_password')->default(true);

            // Exige foto no ato? Útil em entrega de encomenda.
            $table->boolean('requires_photo')->default(false);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['space_id', 'slug']);
        });

        // Quem pode confirmar: funcionário, terceirizado, morador.
        Schema::create('confirmation_actors', function (Blueprint $table) {
            $table->id();

            $table->foreignId('space_id')
                  ->constrained('spaces')
                  ->cascadeOnDelete();

            $table->string('name');
            $table->string('external_id', 60)->nullable();  // matrícula, apto
            $table->string('role', 60)->nullable();

            // Senha do confirmador, hasheada. É a "senha do funcionário"
            // do requisito de EPI.
            $table->string('password_hash')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['space_id', 'external_id']);
        });

        Schema::create('confirmation_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('space_id')
                  ->constrained('spaces')
                  ->cascadeOnDelete();

            $table->foreignId('template_id')
                  ->constrained('confirmation_templates')
                  ->cascadeOnDelete();

            // O QR lido. Nulo quando a confirmação é feita pelo painel.
            $table->foreignId('entity_id')
                  ->nullable()
                  ->constrained('entities')
                  ->nullOnDelete();

            $table->foreignId('actor_id')
                  ->nullable()
                  ->constrained('confirmation_actors')
                  ->nullOnDelete();

            // Respostas dos campos do template.
            $table->json('payload')->nullable();

            // A PROVA. Sem isso o evento não vale como certificação.
            $table->boolean('password_verified')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->timestamp('confirmed_at');

            $table->timestamps();

            $table->index(['space_id', 'template_id', 'confirmed_at']);
        });

        // --- White-label e patrocínio (T3-R02, T3-R03, T3-R04) ---
        Schema::table('credit_batches', function (Blueprint $table) {
            // Empresa que patrocinou o lote. A página do QR criado com esse
            // crédito exibe a marca dela (T3-R03).
            $table->foreignId('sponsor_space_id')
                  ->nullable()
                  ->after('organization_id')
                  ->constrained('spaces')
                  ->nullOnDelete();

            // URL de destino do patrocinador (ex.: promoção da farmácia).
            $table->string('sponsor_url')->nullable()->after('sponsor_space_id');
        });
    }

    public function down(): void
    {
        Schema::table('credit_batches', function (Blueprint $table) {
            $table->dropForeign(['sponsor_space_id']);
            $table->dropColumn(['sponsor_space_id', 'sponsor_url']);
        });

        Schema::dropIfExists('confirmation_events');
        Schema::dropIfExists('confirmation_actors');
        Schema::dropIfExists('confirmation_templates');
        Schema::dropIfExists('api_keys');
    }
};
