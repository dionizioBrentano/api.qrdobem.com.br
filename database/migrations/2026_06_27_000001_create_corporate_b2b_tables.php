<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('document')->unique()->nullable();
            $table->foreignId('owner_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('organization_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['admin', 'manager', 'affiliate'])->default('affiliate');
            $table->timestamps();
            
            $table->unique(['organization_id', 'tenant_id']);
        });

        Schema::create('credit_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('recipient_tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->integer('amount_total');
            $table->integer('amount_available');
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->enum('status', ['active', 'blocked', 'exhausted'])->default('active');
            $table->timestamps();
        });

        Schema::create('price_tables', function (Blueprint $table) {
            $table->id();
            $table->enum('target_type', ['global', 'group', 'tenant', 'promotion'])->default('global');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->decimal('unit_price', 10, 2);
            $table->timestamps();
        });
        
        Schema::table('entities', function (Blueprint $table) {
            $table->foreignId('credit_batch_id')->nullable()->constrained('credit_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropForeign(['credit_batch_id']);
            $table->dropColumn('credit_batch_id');
        });
        Schema::dropIfExists('price_tables');
        Schema::dropIfExists('credit_batches');
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('organizations');
    }
};
