<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 0, entrega 0.2 — Membros e permissões granulares do espaço.
 * Referência: PLANO_TRILHAS_2026-08.md, fundação F2.
 *
 * Substitui, no contexto do espaço, o enum de três papéis do pivot
 * `organization_user` (admin | manager | affiliate). Aquele pivot continua
 * existindo para a relação com a pessoa jurídica; quem manda no que pode
 * ser feito dentro de um espaço é esta tabela.
 *
 * Motivação (T1-R04): "o fundador do grupo familiar pode delegar poderes
 * de edição e gestão aos demais membros para gerenciar familiares, pets e
 * bens". Delegação por escopo não cabe num enum de três valores — um
 * membro pode poder editar pets e não poder remover pessoas.
 *
 * Modelo: papel define um conjunto padrão de permissões (aplicado pelo
 * SpacePolicy no código), e `space_member_permissions` registra as
 * concessões explícitas por membro. A permissão explícita sempre vence.
 *
 * Vocabulário de permissões (validado na aplicação, não no banco, para não
 * exigir migration a cada permissão nova):
 *   entity.view      entity.create    entity.edit     entity.delete
 *   member.view      member.invite    member.remove   member.permission
 *   finance.view     finance.manage
 *   panic.configure  panic.trigger
 *   space.edit       space.delete
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('space_members', function (Blueprint $table) {
            $table->id();

            $table->foreignId('space_id')
                  ->constrained('spaces')
                  ->cascadeOnDelete();

            $table->foreignId('tenant_id')
                  ->constrained('tenants')
                  ->cascadeOnDelete();

            // owner   — dono do espaço, permissões todas, não removível
            // admin   — pode tudo, inclusive delegar
            // manager — gere entidades e membros, não mexe em finanças
            // member  — acesso de leitura + o que for concedido explicitamente
            $table->enum('role', ['owner', 'admin', 'manager', 'member'])
                  ->default('member');

            // Rastreabilidade do convite: quem chamou quem para o espaço.
            $table->foreignId('invited_by_tenant_id')
                  ->nullable()
                  ->constrained('tenants')
                  ->nullOnDelete();

            // Nulo enquanto o convite não foi aceito. Membro pendente não
            // exerce permissão alguma.
            $table->timestamp('accepted_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Uma pessoa entra uma vez só em cada espaço.
            $table->unique(['space_id', 'tenant_id']);
            $table->index(['tenant_id', 'role']);
        });

        Schema::create('space_member_permissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('space_member_id')
                  ->constrained('space_members')
                  ->cascadeOnDelete();

            // Nome da permissão (ver vocabulário no cabeçalho).
            $table->string('permission', 50);

            // Quem concedeu — exigido para auditoria de delegação.
            $table->foreignId('granted_by_tenant_id')
                  ->nullable()
                  ->constrained('tenants')
                  ->nullOnDelete();

            $table->timestamps();

            $table->unique(['space_member_id', 'permission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('space_member_permissions');
        Schema::dropIfExists('space_members');
    }
};
