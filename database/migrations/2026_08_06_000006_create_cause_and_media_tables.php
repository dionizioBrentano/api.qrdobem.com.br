<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 — Grupos e Causas.
 * Referência: PLANO_TRILHAS_2026-08.md, T2-R01 a T2-R05.
 *
 * TRÊS COISAS NESTA MIGRATION
 *
 * 1. `cause_profiles` — a vitrine pública da causa (T2-R04, T2-R05).
 *    Fica em tabela separada de `spaces` porque só espaço do tipo `cause`
 *    tem isso, e inchar `spaces` com colunas que 3 dos 4 tipos nunca usam
 *    seria pior.
 *
 * 2. `media_items` — fotos e vídeos com moderação (T2-R05, e depois
 *    T4-R07). Polimórfico: serve a causa hoje e ao repasse na Fase 4.
 *
 * 3. `qr_print_batches` — geração e impressão de QR em lote (T2-R03).
 *
 * SEM CNPJ (T2-R01): nada aqui exige documento de pessoa jurídica. Pessoa
 * física liderando iniciativa autônoma cria o espaço `cause` com o próprio
 * CPF, que o Gate 1 já validou.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cause_profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('space_id')
                  ->unique()
                  ->constrained('spaces')
                  ->cascadeOnDelete();

            $table->string('headline');                 // chamada curta
            $table->text('story')->nullable();          // o que a causa faz
            $table->string('category', 50)->nullable(); // animal, criança, estomizado...
            $table->string('city', 120)->nullable();
            $table->string('state', 2)->nullable();

            // Meta de arrecadação. Nula = sem meta declarada, o que é
            // legítimo: nem toda causa trabalha com meta fechada.
            $table->decimal('goal_amount', 12, 2)->nullable();

            // Total já arrecadado. Denormalizado de propósito: a vitrine é
            // pública e não pode somar a tabela de doações a cada visita.
            // Atualizado quando a doação é confirmada (Fase 4).
            $table->decimal('raised_amount', 12, 2)->default(0);

            // Prestação de contas em texto livre — a prova social escrita.
            $table->text('accountability')->nullable();

            // Visível publicamente? Causa em rascunho não aparece.
            $table->boolean('is_published')->default(false);

            $table->timestamps();

            $table->index(['is_published', 'category']);
        });

        Schema::create('media_items', function (Blueprint $table) {
            $table->id();

            // Polimórfico: 'space' hoje, 'disbursement' na Fase 4.
            $table->string('owner_type', 40);
            $table->unsignedBigInteger('owner_id');

            $table->foreignId('uploaded_by_tenant_id')
                  ->nullable()
                  ->constrained('tenants')
                  ->nullOnDelete();

            $table->string('path');              // caminho no storage privado
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('caption', 500)->nullable();

            // MODERAÇÃO OBRIGATÓRIA (T2-R05, T4-R07).
            // Mídia enviada por terceiro pode trazer rosto de menor, dado
            // sensível no fundo da foto ou conteúdo impróprio. Publicação
            // automática é inaceitável — por isso nasce 'pending'.
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');

            $table->foreignId('moderated_by_tenant_id')
                  ->nullable()
                  ->constrained('tenants')
                  ->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_type', 'owner_id', 'status']);
        });

        Schema::create('qr_print_batches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('space_id')
                  ->constrained('spaces')
                  ->cascadeOnDelete();

            $table->foreignId('created_by_tenant_id')
                  ->nullable()
                  ->constrained('tenants')
                  ->nullOnDelete();

            $table->string('label')->nullable();  // "Campanha out/2026"
            $table->unsignedInteger('quantity');

            // Códigos gerados, um por linha do lote. JSON e não tabela
            // separada: são lidos sempre juntos, na hora de imprimir.
            $table->json('codes')->nullable();

            $table->enum('status', ['pending', 'generating', 'ready', 'failed'])
                  ->default('pending');

            $table->text('error')->nullable();
            $table->timestamp('generated_at')->nullable();

            $table->timestamps();

            $table->index(['space_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_print_batches');
        Schema::dropIfExists('media_items');
        Schema::dropIfExists('cause_profiles');
    }
};
