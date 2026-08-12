<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entity_emergency_declarations', function (Blueprint $table) {
            $table->text('declarant_cpf_encrypted')->nullable()->change();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('location_accuracy')->nullable();
            $table->text('note')->nullable();
            $table->string('ip')->nullable();
            $table->text('user_agent')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('entity_emergency_declarations', function (Blueprint $table) {
            $table->text('declarant_cpf_encrypted')->nullable(false)->change();
            $table->dropColumn(['latitude', 'longitude', 'location_accuracy', 'note', 'ip', 'user_agent']);
        });
    }
};
