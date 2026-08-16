<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('entity_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->string('unique_code')->index();
            $table->string('entity_type'); // person, pet, object
            $table->timestamp('read_at')->index();
            $table->string('ip_hash')->nullable();
            $table->string('user_agent', 1000)->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('source')->nullable()->default('public_page');
            $table->json('meta')->nullable();
            $table->timestamps();
            // No soft deletes (immutable)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entity_reads');
    }
};
