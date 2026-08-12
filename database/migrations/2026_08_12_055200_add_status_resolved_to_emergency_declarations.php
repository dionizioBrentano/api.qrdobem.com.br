<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entity_emergency_declarations', function (Blueprint $table) {
            $table->string('status')->default('open');
            $table->timestamp('resolved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('entity_emergency_declarations', function (Blueprint $table) {
            $table->dropColumn(['status', 'resolved_at']);
        });
    }
};
