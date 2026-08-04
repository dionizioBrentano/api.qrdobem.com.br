<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_object_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained()->cascadeOnDelete();
            // Descrição nasce PRIVADA: revelar o que o objeto é publicamente
            // é risco de segurança, não ajuda.
            $table->text('description')->nullable();
            $table->boolean('description_is_public')->default(false);
            // Texto pensado para ir impresso junto ao QR. Sempre público.
            $table->string('public_label', 200)->nullable();
            // Avisos de manuseio: conjunto fixo de flags 1-para-1, sem tabela própria.
            $table->boolean('handling_fragile')->default(false);
            $table->boolean('handling_light_sensitive')->default(false);
            $table->boolean('handling_keep_refrigerated')->default(false);
            $table->boolean('handling_do_not_invert')->default(false);
            $table->boolean('handling_sentimental_value')->default(false);
            $table->text('handling_notes_extra')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_object_fields');
    }
};
