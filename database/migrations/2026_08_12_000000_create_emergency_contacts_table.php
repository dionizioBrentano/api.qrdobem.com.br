<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('space_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('invite_token')->unique();
            $table->string('status')->default('pending'); // pending | accepted | revoked
            $table->string('term_version')->nullable();
            $table->timestamp('term_accepted_at')->nullable();
            $table->json('push_subscription')->nullable();
            $table->foreignId('linked_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('owner_tenant_id');
            $table->index('space_id');
            $table->index('entity_id');
            $table->index('status');
        });
    }

    public function down()
    {
        Schema::dropIfExists('emergency_contacts');
    }
};
