<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — doação SEM login (guest checkout).
 *
 * Altera a tabela do checkout, `donation_causes` (antes `donations`, antes da
 * separação dos agregados). Doar não exige conta: o doador se identifica por
 * doação. `donor_name` e `donor_email` já existem na tabela e servem ao
 * recibo e à conciliação. Falta o documento e o consentimento — aqui.
 *
 * SEGURANÇA DO CPF (mesmo padrão de Person / CpfIdentityService)
 *   donor_document_encrypted  CPF cifrado em repouso (cast `encrypted` no
 *                             model — não determinístico, não pesquisável).
 *   donor_document_hash       blind index HMAC-SHA256 (CpfIdentityService::
 *                             hash), determinístico e indexado, para
 *                             conciliação SEM manter o CPF legível.
 * O CPF nunca aparece em JSON (está em $hidden no model) nem em log.
 *
 * Diferente de Person: aqui NÃO se cria pessoa nem se liga a conta. Guest é
 * guest — a fronteira de segurança do CpfIdentityService (CPF não resolve
 * vínculo de terceiro) fica preservada porque só gravamos, não consultamos
 * vínculo a partir do CPF informado.
 *
 * `lgpd_consent_at` registra QUANDO o doador autorizou o uso dos dados para
 * pagamento, recibo e conciliação — a marca temporal é a prova do aceite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_causes', function (Blueprint $table) {
            $table->text('donor_document_encrypted')->nullable()->after('donor_email');
            $table->string('donor_document_hash', 64)->nullable()->after('donor_document_encrypted');
            $table->timestamp('lgpd_consent_at')->nullable()->after('donor_document_hash');

            // Conciliação/suporte pode precisar achar doações do mesmo CPF
            // sem decifrar nada — daí o índice no blind index.
            $table->index('donor_document_hash');
        });
    }

    public function down(): void
    {
        Schema::table('donation_causes', function (Blueprint $table) {
            $table->dropIndex(['donor_document_hash']);
            $table->dropColumn([
                'donor_document_encrypted',
                'donor_document_hash',
                'lgpd_consent_at',
            ]);
        });
    }
};
