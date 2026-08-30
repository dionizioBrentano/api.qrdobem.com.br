<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * política de retenção pendente de decisão do dono.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->string('device_id', 64)->nullable();
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->unsignedSmallInteger('accuracy_meters')->nullable();
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();

            $table->index(['entity_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_positions');
    }
};
