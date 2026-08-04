<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Apelido escolhido pelo usuário. Diferente de 'name', que é o nome
            // real usado em documentos, CPF e faturas: o apelido é a identificação
            // padrão do tenant quando ele atua como benfeitor no chat de uma entidade.
            $table->string('nickname')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('nickname');
        });
    }
};
