<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 — 2FA (T1-R05) e Botão de Pânico (T1-R07).
 * Referência: PLANO_TRILHAS_2026-08.md.
 *
 * DECISÃO DE PRODUTO (06/08/2026, do proprietário):
 * O Botão de Pânico NÃO espera o WhatsApp. O frontend será instalado como
 * app (PWA) e funciona ele próprio como alarme. Esta é a versão rústica,
 * para teste; a sofisticação vem depois. Por isso as tabelas já nascem com
 * o canal registrado por destinatário — quando o WhatsApp entrar, é só
 * outro valor em `channel`, sem migration nova.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 2FA (T1-R05) ---
        Schema::create('tenant_two_factor', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                  ->unique()
                  ->constrained('tenants')
                  ->cascadeOnDelete();

            // Segredo TOTP, cifrado pelo cast do model. Nunca sai em JSON
            // depois da confirmação — só na tela de configuração.
            $table->text('secret');

            // Nulo = configuração iniciada mas não confirmada. Só conta como
            // 2FA ativo depois que o usuário digita um código válido, senão
            // ele se tranca fora da conta com um segredo que não guardou.
            $table->timestamp('confirmed_at')->nullable();

            // Códigos de recuperação, hasheados. Array serializado + cifra.
            $table->text('recovery_codes')->nullable();

            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();
        });

        // --- Botão de Pânico (T1-R07) ---
        Schema::create('panic_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('space_id')
                  ->constrained('spaces')
                  ->cascadeOnDelete();

            // Qual entidade acionou. Nulo quando o acionamento vem do app
            // sem QR intermediário (o caso rústico do frontend-alarme).
            $table->foreignId('entity_id')
                  ->nullable()
                  ->constrained('entities')
                  ->nullOnDelete();

            // Quem acionou, quando autenticado. Acionamento público via QR
            // não tem tenant — e é justamente o caso de quem encontrou a
            // pessoa na rua.
            $table->foreignId('triggered_by_tenant_id')
                  ->nullable()
                  ->constrained('tenants')
                  ->nullOnDelete();

            // 'app'    — disparado dentro do frontend instalado (PWA)
            // 'qr'     — leitura pública do QR de pânico
            // 'manual' — acionado pelo painel
            $table->string('source', 20)->default('app');

            $table->enum('status', ['open', 'resolved', 'false_alarm'])->default('open');

            // Geolocalização do acionamento, quando o navegador permitir.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('location_accuracy')->nullable();

            $table->text('note')->nullable();

            $table->timestamp('triggered_at');
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['space_id', 'status']);
        });

        // Um registro por destinatário: é o que permite ao painel dizer
        // quem recebeu e quem não recebeu o alerta.
        Schema::create('panic_recipients', function (Blueprint $table) {
            $table->id();

            $table->foreignId('panic_event_id')
                  ->constrained('panic_events')
                  ->cascadeOnDelete();

            $table->foreignId('tenant_id')
                  ->nullable()
                  ->constrained('tenants')
                  ->nullOnDelete();

            // 'mail' hoje; 'whatsapp', 'sms', 'push' quando existirem.
            $table->string('channel', 20);

            // Destino usado (e-mail ou telefone). Guardado como foi enviado,
            // porque o cadastro pode mudar depois e a prova é do momento.
            $table->string('destination');

            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->string('provider_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->index(['panic_event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panic_recipients');
        Schema::dropIfExists('panic_events');
        Schema::dropIfExists('tenant_two_factor');
    }
};
