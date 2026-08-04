<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Registra de qual conversa este tenant se originou, quando ele veio
            // do funil de benfeitor. Null para todo mundo que chegou por outro caminho.
            $table->foreignId('originating_conversation_id')
                ->nullable()
                ->constrained('entity_conversations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('originating_conversation_id');
        });
    }
};
