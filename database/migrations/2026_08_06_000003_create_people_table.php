<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 0, entrega 0.8 — Identidade da pessoa natural (`people`).
 * Referência: PLANO_TRILHAS_2026-08.md, fundação F10 (TX-R02, TX-R03, TX-R04).
 *
 * PROBLEMA QUE RESOLVE
 * Um CPF pode ter várias contas com e-mails diferentes — situação real do
 * proprietário (3 contas, 3 e-mails, 1 CPF). Isso é legítimo e não deve ser
 * bloqueado; o que falta é o sistema saber que as três são a mesma pessoa,
 * para exibir os vínculos num painel só e permitir troca sem logout.
 *
 * POR QUE `cpf_hash` E NÃO BUSCA DIRETA
 * Dois problemas somados:
 *   (a) `tenants.cpf` está hoje em TEXTO PURO na tabela — dado pessoal
 *       exposto no banco sem necessidade;
 *   (b) o cast `encrypted` do Laravel (usado em tenant_documents) não é
 *       determinístico, logo um campo cifrado NÃO é pesquisável.
 * O blind index resolve os dois: `cpf_hash` = HMAC-SHA256 do CPF com a
 * APP_KEY, indexado e único; `cpf_encrypted` guarda o valor real cifrado.
 * Localiza-se por CPF sem manter CPF legível.
 *
 * FRONTEIRA DE SEGURANÇA (registrada no plano, §3.F10)
 * CPF NÃO é segredo — está em nota fiscal, é pedido em farmácia e vaza em
 * incidente de terceiro. Portanto o CPF nunca é credencial de leitura:
 * digitar um CPF não revela vínculo nenhum. As contas são ligadas ao
 * `person` porque cada uma comprovou a posse do CPF dentro dela mesma
 * (Gate 1: OTP de e-mail + CPF válido), e a tela "Minhas contas" só mostra
 * o que já está ligado ao person do usuário autenticado.
 *
 * SOBRE `tenants.cpf` (LEGADO)
 * Esta migration NÃO remove nem cifra a coluna `tenants.cpf`. Cifrar em
 * lugar exigiria reescrever todas as linhas e quebraria a leitura caso o
 * backfill falhasse no meio. A sequência segura é:
 *   1. esta migration (cria people, person_id, cpf_hash)
 *   2. `php artisan people:backfill` (agrupa contas por CPF)
 *   3. validação com dados reais
 *   4. migration de limpeza, futura, que remove `tenants.cpf`
 * Enquanto a 4 não roda, `tenants.cpf` continua funcionando como está e
 * nada no sistema atual quebra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();

            // Blind index: HMAC-SHA256 do CPF apenas com dígitos.
            // 64 caracteres hexadecimais. Unique = uma pessoa por CPF.
            $table->string('cpf_hash', 64)->unique();

            // Valor real, cifrado pelo cast do model (AES via APP_KEY).
            $table->text('cpf_encrypted');

            // Marcado quando ao menos uma conta da pessoa concluiu o Gate 1.
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('tenants', function (Blueprint $table) {
            // A conta pertence a uma pessoa. Nullable porque a conta existe
            // desde o login, e o CPF só chega no Gate 1.
            $table->foreignId('person_id')
                  ->nullable()
                  ->after('id')
                  ->constrained('people')
                  ->nullOnDelete();

            // Cópia do blind index na conta. Redundante em relação a
            // people.cpf_hash, e proposital: permite localizar a conta por
            // CPF sem join, e detectar divergência entre os dois lados.
            $table->string('cpf_hash', 64)->nullable()->after('cpf');

            $table->index('cpf_hash');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropForeign(['person_id']);
            $table->dropColumn('person_id');
            $table->dropIndex(['cpf_hash']);
            $table->dropColumn('cpf_hash');
        });

        Schema::dropIfExists('people');
    }
};
