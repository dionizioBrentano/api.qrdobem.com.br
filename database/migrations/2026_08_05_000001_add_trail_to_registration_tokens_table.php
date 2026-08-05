<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_tokens', function (Blueprint $table) {
            // Trilha escolhida na Home (pet/person/object). Viaja junto do token
            // porque o sessionStorage não sobrevive ao cadastro por link de e-mail.
            $table->string('trail')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('registration_tokens', function (Blueprint $table) {
            $table->dropColumn('trail');
        });
    }
};
