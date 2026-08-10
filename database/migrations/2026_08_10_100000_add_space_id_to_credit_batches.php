<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_batches', function (Blueprint $table) {
            $table->foreignId('space_id')->nullable()->after('organization_id')->constrained('spaces')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('credit_batches', function (Blueprint $table) {
            $table->dropForeign(['space_id']);
            $table->dropColumn('space_id');
        });
    }
};
