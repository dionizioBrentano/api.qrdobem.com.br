<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1-para-1 com a entidade: um conjunto de dados por pet.
        Schema::create('entity_pet_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained()->cascadeOnDelete();
            $table->string('species');
            $table->string('species_other_description')->nullable();
            $table->string('size')->nullable();
            $table->boolean('size_is_public')->default(true);
            $table->text('color')->nullable();
            $table->boolean('color_is_public')->default(true);
            $table->string('is_neutered')->nullable();
            $table->boolean('is_neutered_is_public')->default(true);
            $table->text('physical_description')->nullable();
            $table->boolean('physical_description_is_public')->default(true);
            $table->text('clinical_notes')->nullable();
            $table->boolean('clinical_notes_is_public')->default(true);
            $table->text('reference_contact')->nullable();
            $table->boolean('reference_contact_is_public')->default(false);
            $table->boolean('vaccinations_is_public')->default(true);
            $table->timestamps();
        });

        // 1-para-muitos: várias doses ao longo do tempo.
        Schema::create('entity_vaccinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained()->cascadeOnDelete();
            $table->string('vaccine_name');
            $table->date('applied_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_vaccinations');
        Schema::dropIfExists('entity_pet_fields');
    }
};
