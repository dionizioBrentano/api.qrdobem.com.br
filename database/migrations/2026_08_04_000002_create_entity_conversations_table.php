<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained()->cascadeOnDelete();
            // Código de recuperação escolhido pelo benfeitor, guardado como hash
            // bcrypt (Hash::make). Só existe em texto puro na mensagem de sistema
            // criada no momento em que a conversa nasce.
            $table->string('recovery_code_hash')->nullable();
            $table->string('benefactor_nickname')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_conversations');
    }
};
