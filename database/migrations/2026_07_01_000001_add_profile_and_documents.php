<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Campos novos no tenant
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('email')->nullable()->after('name');
            $table->timestamp('email_verified_at')->nullable()->after('phone');
            $table->string('address_street')->nullable();
            $table->string('address_number', 20)->nullable();
            $table->string('address_complement')->nullable();
            $table->string('address_neighborhood')->nullable();
            $table->string('address_city')->nullable();
            $table->string('address_state', 2)->nullable();
            $table->string('address_zipcode', 9)->nullable();
        });

        // 2. Documentos do tenant (CPF, RG, CNH, etc.) — criptografados via cast
        Schema::create('tenant_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('document_type'); // cpf, cin, rg, cnh, passport, etc.
            $table->text('document_number'); // encrypted via Laravel cast
            $table->string('document_country', 5)->default('BR');
            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'document_type']);
        });

        // 3. Aceite de termos de responsabilidade (rastreabilidade jurídica)
        Schema::create('tenant_term_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained()->nullOnDelete();
            $table->string('term_type'); // person, pet, object
            $table->string('term_version');
            $table->string('ip_address');
            $table->string('user_agent')->nullable();
            $table->timestamp('accepted_at');
            $table->timestamps();
        });

        // 4. Status granular na entity (pending_term → active → suspended)
        Schema::table('entities', function (Blueprint $table) {
            $table->enum('status', ['pending_term', 'active', 'suspended'])
                  ->default('pending_term')->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn('status');
        });
        Schema::dropIfExists('tenant_term_acceptances');
        Schema::dropIfExists('tenant_documents');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'email', 'email_verified_at',
                'address_street', 'address_number', 'address_complement',
                'address_neighborhood', 'address_city', 'address_state', 'address_zipcode',
            ]);
        });
    }
};
