<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6 — Módulo Premium de Saúde e expansões.
 * Referência: PLANO_TRILHAS_2026-08.md, T1-R08 a T1-R11, T2-R06, T2-R07.
 *
 * BASE DE MEDICAMENTOS — decisão do proprietário (05/08/2026)
 * Descartada a integração com fontes oficiais em cascata. O processo é:
 * escaneia o código de barras → busca na internet na primeira vez →
 * pergunta ao usuário se é aquele produto → confirma. Com 3 confirmações
 * independentes, o registro vira confiável.
 *
 * Por que é melhor que a base oficial: a oficial precisa estar certa para
 * todo mundo antes do primeiro uso; esta cresce sozinha, validada por quem
 * tem a caixa na mão — a única pessoa capaz de dizer se está certo.
 *
 * A REGRA QUE PROTEGE A BASE: divergência NÃO vira confiança. Dois usuários
 * apontando produtos diferentes para o mesmo EAN levam o registro a
 * `conflict`, não a `trusted`. Sem isso, um engano em cadeia contamina uma
 * base que sugere horário de remédio.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- Base de medicamentos (T1-R09) ---
        Schema::create('medications', function (Blueprint $table) {
            $table->id();

            // Código de barras da caixa.
            $table->string('ean', 20)->unique();

            $table->string('name');
            $table->string('presentation')->nullable();  // "1000mg, 20 comprimidos"
            $table->string('laboratory')->nullable();
            $table->string('active_ingredient')->nullable();

            // Registro MS, quando a busca trouxer. É a chave exata para
            // achar a bula certa — nome comercial é ambíguo entre genérico,
            // referência e apresentações.
            $table->string('registry_number', 30)->nullable();

            // pending  → precisa de confirmação do usuário
            // trusted  → confirmado por N pessoas distintas
            // conflict → usuários discordam; volta a perguntar
            $table->enum('status', ['pending', 'trusted', 'conflict'])->default('pending');

            $table->unsignedInteger('confirmations_count')->default(0);

            // De onde veio o dado na primeira vez, para auditoria.
            $table->string('source', 60)->nullable();

            $table->timestamps();

            $table->index('status');
        });

        Schema::create('medication_confirmations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('medication_id')
                  ->constrained('medications')
                  ->cascadeOnDelete();

            $table->foreignId('tenant_id')
                  ->constrained('tenants')
                  ->cascadeOnDelete();

            // confirmed | corrected
            $table->string('action', 20)->default('confirmed');

            // Quando o usuário corrige, o que ele disse que era.
            $table->json('corrected_payload')->nullable();

            $table->timestamps();

            // Um voto por pessoa: é o que faz "3 confirmações" significar
            // 3 PESSOAS, e não a mesma clicando três vezes.
            $table->unique(['medication_id', 'tenant_id']);
        });

        // Bula em cache. Buscada uma vez, guardada aqui.
        Schema::create('medication_leaflets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('medication_id')
                  ->constrained('medications')
                  ->cascadeOnDelete();

            $table->string('source_url', 500)->nullable();
            $table->longText('content')->nullable();      // texto extraído
            $table->text('posology_excerpt')->nullable(); // trecho da posologia

            $table->timestamp('fetched_at')->nullable();

            $table->timestamps();
        });

        // --- Prescrições e horários (T1-R10, T1-R11) ---
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('entity_id')
                  ->constrained('entities')
                  ->cascadeOnDelete();

            $table->foreignId('medication_id')
                  ->nullable()
                  ->constrained('medications')
                  ->nullOnDelete();

            // Nome livre, para remédio que não está na base ainda.
            $table->string('medication_name');

            $table->string('dosage', 120)->nullable();          // "1 comprimido"
            $table->unsignedSmallInteger('interval_hours')->nullable(); // 8
            $table->time('first_dose_at')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();                // null = contínuo

            $table->text('notes')->nullable();
            $table->string('prescriber')->nullable();

            // Horários calculados a partir do intervalo. Guardados para o
            // .ics não recalcular a cada exportação, e para o usuário poder
            // ajustar manualmente sem perder o ajuste.
            $table->json('schedule_times')->nullable();

            // A sugestão veio da bula? Registrado para a tela poder exibir
            // a fonte — o cruzamento é ASSISTIVO, nunca prescritivo.
            $table->boolean('suggested_from_leaflet')->default(false);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'is_active']);
        });

        // --- Diário de saúde (T1-R08) ---
        Schema::create('health_diary_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('entity_id')
                  ->constrained('entities')
                  ->cascadeOnDelete();

            $table->foreignId('created_by_tenant_id')
                  ->nullable()
                  ->constrained('tenants')
                  ->nullOnDelete();

            // symptom | appointment | exam | medication_taken | vaccination | note
            $table->string('kind', 30)->default('note');

            $table->string('title');
            $table->text('description')->nullable();

            // Medição livre: pressão, glicemia, peso, temperatura.
            $table->string('measure_key', 40)->nullable();
            $table->string('measure_value', 60)->nullable();

            $table->foreignId('prescription_id')
                  ->nullable()
                  ->constrained('prescriptions')
                  ->nullOnDelete();

            $table->timestamp('occurred_at');

            $table->timestamps();

            $table->index(['entity_id', 'occurred_at']);
        });

        // --- Apadrinhamento (T2-R06) ---
        Schema::create('sponsorships', function (Blueprint $table) {
            $table->id();

            $table->foreignId('beneficiary_id')
                  ->constrained('beneficiaries')
                  ->cascadeOnDelete();

            $table->foreignId('sponsor_tenant_id')
                  ->constrained('tenants')
                  ->cascadeOnDelete();

            $table->foreignId('subscription_id')
                  ->nullable()
                  ->constrained('donation_subscriptions')
                  ->nullOnDelete();

            $table->decimal('monthly_amount', 12, 2)->nullable();

            $table->enum('status', ['active', 'paused', 'ended'])->default('active');

            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();

            $table->timestamps();

            $table->unique(['beneficiary_id', 'sponsor_tenant_id']);
        });

        // --- Mapa de calor (T2-R07) ---
        // Agregação por célula geográfica. Sem exigência de k-anonimato,
        // por decisão do proprietário em 05/08/2026.
        Schema::create('heatmap_cells', function (Blueprint $table) {
            $table->id();

            // Célula de ~1,1 km: latitude e longitude arredondadas a 2
            // casas decimais. Guardar o ponto exato de cada leitura faria
            // a tabela crescer sem limite e não melhoraria o mapa.
            $table->decimal('cell_lat', 6, 2);
            $table->decimal('cell_lng', 6, 2);

            // person | pet | object — o mapa separa por tipo de ocorrência.
            $table->string('entity_type', 20);

            $table->unsignedInteger('reads_count')->default(0);

            $table->timestamp('last_read_at')->nullable();

            $table->timestamps();

            $table->unique(['cell_lat', 'cell_lng', 'entity_type'], 'heatmap_cell_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heatmap_cells');
        Schema::dropIfExists('sponsorships');
        Schema::dropIfExists('health_diary_entries');
        Schema::dropIfExists('prescriptions');
        Schema::dropIfExists('medication_leaflets');
        Schema::dropIfExists('medication_confirmations');
        Schema::dropIfExists('medications');
    }
};
