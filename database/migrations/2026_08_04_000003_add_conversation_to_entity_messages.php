<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entity_messages', function (Blueprint $table) {
            // Nullable: as mensagens avulsas criadas antes do chat mediado
            // continuam válidas e não pertencem a nenhuma conversa.
            $table->foreignId('conversation_id')
                ->nullable()
                ->after('entity_id')
                ->constrained('entity_conversations')
                ->nullOnDelete();

            // Default 'benefactor' pelo mesmo motivo: todo registro antigo veio
            // de quem escaneou o QR.
            $table->enum('sender_type', ['benefactor', 'tenant', 'system'])
                ->default('benefactor')
                ->after('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::table('entity_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('conversation_id');
            $table->dropColumn('sender_type');
        });
    }
};
