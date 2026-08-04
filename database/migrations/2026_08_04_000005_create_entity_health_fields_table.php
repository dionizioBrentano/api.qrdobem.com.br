<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_health_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained()->cascadeOnDelete();
            // Um dos 8 valores da lista fechada (ver EntityHealthField::FIELD_KEYS).
            $table->string('field_key');
            $table->text('field_value')->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamps();

            $table->index(['entity_id', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_health_fields');
    }
};
