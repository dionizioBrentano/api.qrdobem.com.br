<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_emergency_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained()->cascadeOnDelete();
            // Criptografia REVERSÍVEL (cast 'encrypted', AES via APP_KEY), mesmo
            // padrão de tenant_documents.document_number. Não é hash: precisa
            // poder ser decifrado por superadmin em caso de necessidade legal.
            $table->text('declarant_cpf_encrypted');
            $table->timestamp('declared_at');
            $table->timestamps();

            $table->index(['entity_id', 'declared_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_emergency_declarations');
    }
};
