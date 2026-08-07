<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — rateio da doação (taxa da OSCIP/plataforma sobre o bruto).
 *
 * A tabela `donations` já existe (2026_08_06_000007). Esta migration só
 * ACRESCENTA o detalhamento do rateio, sem tocar no que já roda:
 *
 *   amount_gross            valor bruto da doação (o "quanto quero doar")
 *   platform_fee_percent    taxa aplicada, gravada junto para auditoria
 *   platform_fee_amount     taxa da plataforma em reais (sobre o bruto)
 *   payment_fee_amount      custo real do meio de pagamento, à parte
 *   amount_to_cause         valor líquido que chega à causa
 *   cover_fees              se o doador bancou as taxas
 *   extra_platform_support  apoio extra opcional só para a OSCIP/plataforma
 *
 * SEMÂNTICA DO `amount` (coluna que já existia)
 * `amount` continua sendo O QUE O DOADOR PAGA — é o valor que o Mercado Pago
 * cobra e o que o WebhookController confere (transaction_amount vs amount).
 * Com cover_fees, `amount` = bruto + taxas; sem, `amount` = bruto (+ apoio).
 * O bruto "puro" fica em `amount_gross`. Guardar a taxa em % e em R$ junto do
 * registro é o que permite reconstruir o rateio anos depois, mesmo que a
 * taxa da config mude.
 *
 * Todas as colunas são nullable/com default: doações antigas ficam sem o
 * detalhamento (amount_to_cause NULL), e quem consome trata NULL como
 * "usar o amount" — ver DonationController::markAsPaid().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->decimal('amount_gross', 12, 2)->nullable()->after('amount');
            $table->decimal('platform_fee_percent', 5, 2)->nullable()->after('amount_gross');
            $table->decimal('platform_fee_amount', 12, 2)->default(0)->after('platform_fee_percent');
            $table->decimal('payment_fee_amount', 12, 2)->default(0)->after('platform_fee_amount');
            $table->decimal('amount_to_cause', 12, 2)->nullable()->after('payment_fee_amount');
            $table->boolean('cover_fees')->default(false)->after('amount_to_cause');
            $table->decimal('extra_platform_support', 12, 2)->default(0)->after('cover_fees');
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropColumn([
                'amount_gross',
                'platform_fee_percent',
                'platform_fee_amount',
                'payment_fee_amount',
                'amount_to_cause',
                'cover_fees',
                'extra_platform_support',
            ]);
        });
    }
};
