<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Doações.
 * Referência: PLANO_TRILHAS_2026-08.md, T4-R01 a T4-R09.
 *
 * O MÓDULO DE MAIOR RISCO DO SISTEMA
 * Aqui circula dinheiro de terceiro. Tudo é desenhado para que cada real
 * tenha origem, destino e comprovação rastreáveis — a "contraprova"
 * (T4-R06) é o coração da trilha, não um detalhe.
 *
 * MÁQUINA DE ESTADOS DO REPASSE
 *   requested → approved → sent → confirmed
 *                            └──→ disputed
 * Só `confirmed` conta como repasse concluído, e só se chega lá com a
 * contraprova do beneficiário (QR + fator de autenticação). Sem isso, o
 * dinheiro sai do controle sem prova de que chegou.
 *
 * FATORES DE CONTRAPROVA (F9, decisão de 05/08/2026)
 * `password | facial | tutor`. Ter mais de um é o que impede o repasse de
 * travar quando o beneficiário perde a senha — quem está fora do mundo
 * digital acessa o sistema por um tutor identificado (T4-R09).
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- Doações a causa / checkout (T4-R01 a T4-R04) ---
        // Tabela `donation_causes` (não `donations`): o nome `donations` é do
        // legado de comprovantes (DonationReceipt). Ver a migration
        // 2026_08_07_000003_rename_or_create_donation_receipts.
        Schema::create('donation_causes', function (Blueprint $table) {
            $table->id();

            // Causa escolhida pelo doador (T4-R02). Nula = doação livre,
            // que a OSCIP gestora distribui conforme necessidade.
            $table->foreignId('cause_space_id')
                  ->nullable()
                  ->constrained('spaces')
                  ->nullOnDelete();

            // Doador cadastrado. Nulo = doação anônima, que é caso legítimo
            // e não pode ser bloqueado.
            $table->foreignId('donor_tenant_id')
                  ->nullable()
                  ->constrained('tenants')
                  ->nullOnDelete();

            $table->string('donor_name')->nullable();
            $table->string('donor_email')->nullable();

            $table->decimal('amount', 12, 2);

            // pix | credit_card | citizen_card (Cartão Cidadão)
            $table->string('payment_method', 30);

            $table->enum('status', ['pending', 'paid', 'failed', 'refunded'])
                  ->default('pending');

            // Identificadores do Mercado Pago. `mp_payment_id` é único para
            // que o webhook, que o MP reenvia, não credite duas vezes.
            $table->string('mp_payment_id')->nullable()->unique();
            $table->string('mp_preference_id')->nullable();
            $table->string('mp_status')->nullable();

            // Assinatura recorrente (T4-R04).
            $table->foreignId('subscription_id')->nullable();

            $table->boolean('is_anonymous')->default(false);
            $table->string('message', 500)->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['cause_space_id', 'status']);
            $table->index('donor_tenant_id');
        });

        // --- Doação recorrente (T4-R04) ---
        Schema::create('donation_subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cause_space_id')
                  ->nullable()
                  ->constrained('spaces')
                  ->nullOnDelete();

            $table->foreignId('donor_tenant_id')
                  ->constrained('tenants')
                  ->cascadeOnDelete();

            $table->decimal('amount', 12, 2);
            $table->string('frequency', 20)->default('monthly');

            // Preapproval do Mercado Pago.
            $table->string('mp_preapproval_id')->nullable()->unique();

            $table->enum('status', ['pending', 'active', 'paused', 'cancelled'])
                  ->default('pending');

            $table->timestamp('next_charge_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
        });

        // --- Beneficiários (T4-R05, T4-R09) ---
        Schema::create('beneficiaries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('space_id')
                  ->constrained('spaces')
                  ->cascadeOnDelete();

            // A URL única do beneficiário. É por ela que ele solicita e
            // comprova — o requisito exige que seja obrigatoriamente a dele.
            $table->uuid('unique_code')->unique();

            $table->string('name');
            $table->text('encrypted_document')->nullable();  // CPF cifrado
            $table->string('phone', 30)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 2)->nullable();

            // Senha pessoal da contraprova (T4-R06), hasheada.
            // Nula quando o beneficiário só usa tutor ou biometria.
            $table->string('proof_password_hash')->nullable();

            // Fatores aceitos para este beneficiário: ["password","tutor"].
            $table->json('accepted_proof_factors')->nullable();

            // Tutor (T4-R09): quem opera o sistema por ele. A confirmação
            // do tutor é registrada COMO tutor, nunca como se fosse o
            // próprio beneficiário.
            $table->foreignId('tutor_tenant_id')
                  ->nullable()
                  ->constrained('tenants')
                  ->nullOnDelete();

            // Conta bancária vinculada, para repasse em dinheiro. Cifrada.
            $table->text('encrypted_bank_info')->nullable();

            $table->enum('status', ['active', 'suspended'])->default('active');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['space_id', 'status']);
        });

        // --- Necessidades solicitadas (T4-R05) ---
        Schema::create('beneficiary_needs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('beneficiary_id')
                  ->constrained('beneficiaries')
                  ->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            // product | service | money
            $table->string('kind', 20)->default('product');

            $table->decimal('estimated_amount', 12, 2)->nullable();

            $table->enum('status', ['open', 'in_progress', 'fulfilled', 'cancelled'])
                  ->default('open');

            $table->unsignedTinyInteger('priority')->default(3); // 1 = urgente

            $table->timestamps();

            $table->index(['beneficiary_id', 'status']);
        });

        // --- Repasses (T4-R03, T4-R06) ---
        Schema::create('disbursements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('beneficiary_id')
                  ->constrained('beneficiaries')
                  ->cascadeOnDelete();

            $table->foreignId('need_id')
                  ->nullable()
                  ->constrained('beneficiary_needs')
                  ->nullOnDelete();

            $table->foreignId('cause_space_id')
                  ->nullable()
                  ->constrained('spaces')
                  ->nullOnDelete();

            // product | service | money
            $table->string('kind', 20)->default('product');

            $table->string('description');
            $table->decimal('amount', 12, 2)->nullable();

            // Máquina de estados. Ver o cabeçalho da migration.
            $table->enum('status', ['requested', 'approved', 'sent', 'confirmed', 'disputed'])
                  ->default('requested');

            $table->foreignId('approved_by_tenant_id')
                  ->nullable()
                  ->constrained('tenants')
                  ->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            // --- Contraprova (T4-R06) ---
            // Qual fator foi usado e por quem. Guardar o fator é o que
            // permite auditar depois se a confirmação veio do beneficiário
            // ou do tutor dele.
            $table->string('proof_factor', 20)->nullable();
            $table->foreignId('confirmed_by_tenant_id')
                  ->nullable()
                  ->constrained('tenants')
                  ->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('confirmation_ip', 45)->nullable();

            // --- Ressarcimento ao benfeitor (T4-R08) ---
            // Não há recompensa por resgate. A família pode autorizar o
            // ressarcimento de custo operacional, com TETO.
            $table->foreignId('benefactor_tenant_id')
                  ->nullable()
                  ->constrained('tenants')
                  ->nullOnDelete();
            $table->decimal('reimbursement_amount', 12, 2)->nullable();
            $table->decimal('reimbursement_cap', 12, 2)->nullable();
            $table->boolean('reimbursement_authorized')->default(false);

            $table->timestamps();

            $table->index(['beneficiary_id', 'status']);
            $table->index('cause_space_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disbursements');
        Schema::dropIfExists('beneficiary_needs');
        Schema::dropIfExists('beneficiaries');
        Schema::dropIfExists('donation_subscriptions');
        Schema::dropIfExists('donation_causes');
    }
};
