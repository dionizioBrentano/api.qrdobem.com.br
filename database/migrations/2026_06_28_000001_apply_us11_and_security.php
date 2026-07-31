<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Organizações: Adicionar SoftDeletes
        Schema::table('organizations', function (Blueprint $table) {
            $table->softDeletes();
        });

        // 2. Tenants: Adicionar SoftDeletes
        Schema::table('tenants', function (Blueprint $table) {
            $table->softDeletes();
        });

        // 3. Entidades (QR Codes): Trocar tenant_id por organization_id e adicionar SoftDeletes
        Schema::table('entities', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
            $table->foreignId('organization_id')->after('id')->nullable()->constrained()->cascadeOnDelete();
            $table->softDeletes();
        });

        // 4. Lotes de Crédito: Remover recipient_tenant_id (centralidade organizacional) e add SoftDeletes
        Schema::table('credit_batches', function (Blueprint $table) {
            $table->dropForeign(['recipient_tenant_id']);
            $table->dropColumn('recipient_tenant_id');
            $table->softDeletes();
        });

        // 5. Atributos Personalizados (EAV - US3.3)
        Schema::create('custom_attributes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('owner_type'); // Ex: App\Models\Entity
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
            
            $table->index(['owner_id', 'owner_type']);
        });

        // 6. Comunicação Anônima (US4.5 e Fase 6)
        Schema::create('entity_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->string('sender_name');
            $table->string('sender_contact')->nullable();
            $table->text('message');
            $table->decimal('latitude', 10, 8)->nullable(); // US4.5 GPS
            $table->decimal('longitude', 11, 8)->nullable(); // US4.5 GPS
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_messages');
        Schema::dropIfExists('custom_attributes');

        Schema::table('credit_batches', function (Blueprint $table) {
            $table->foreignId('recipient_tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->dropSoftDeletes();
        });

        Schema::table('entities', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->onDelete('cascade');
            $table->dropSoftDeletes();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
