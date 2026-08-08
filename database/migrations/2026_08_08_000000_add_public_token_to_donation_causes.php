<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('donation_causes', function (Blueprint $table) {
            // nullable só para não quebrar registros antigos no momento da adição.
            $table->string('public_token', 32)->nullable()->unique()->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('donation_causes', function (Blueprint $table) {
            $table->dropColumn('public_token');
        });
    }
};
