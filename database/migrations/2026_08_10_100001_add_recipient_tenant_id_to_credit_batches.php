<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_batches', function (Blueprint $table) {
            // Verifica se a coluna não existe antes de tentar criar
            // Isso previne erros em ambientes de desenvolvimento onde a
            // migration antiga já continha a coluna.
            if (!Schema::hasColumn('credit_batches', 'recipient_tenant_id')) {
                $table->foreignId('recipient_tenant_id')->nullable()->after('creator_tenant_id')->constrained('tenants')->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('credit_batches', function (Blueprint $table) {
            if (Schema::hasColumn('credit_batches', 'recipient_tenant_id')) {
                $table->dropForeign(['recipient_tenant_id']);
                $table->dropColumn('recipient_tenant_id');
            }
        });
    }
};
