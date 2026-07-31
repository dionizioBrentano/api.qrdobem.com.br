<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->uuid('unique_code')->unique();
            $table->enum('type', ['person', 'pet', 'object']);
            $table->text('encrypted_name'); 
            $table->text('encrypted_contact_phone');
            $table->text('encrypted_contact_email')->nullable();
            $table->text('encrypted_medical_info')->nullable();
            $table->text('encrypted_additional_info')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entities');
    }
};
