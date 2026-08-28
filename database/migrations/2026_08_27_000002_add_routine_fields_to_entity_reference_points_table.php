<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entity_reference_points', function (Blueprint $table) {
            $table->foreignId('routine_id')->nullable()->after('entity_id')
                  ->constrained('routines')->nullOnDelete();
            $table->string('address', 255)->nullable()->after('name');
            $table->unsignedInteger('order_index')->default(0)->after('radius_meters');
        });
    }

    public function down(): void
    {
        Schema::table('entity_reference_points', function (Blueprint $table) {
            $table->dropConstrainedForeignId('routine_id');
            $table->dropColumn(['address', 'order_index']);
        });
    }
};
