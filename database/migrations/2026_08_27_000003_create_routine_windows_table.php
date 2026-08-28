<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routine_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routine_id')->constrained('routines')->cascadeOnDelete();
            $table->foreignId('entity_reference_point_id')->nullable()
                  ->constrained('entity_reference_points')->nullOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('tolerance_minutes')->default(15);
            $table->boolean('expects_movement')->default(true);
            $table->timestamps();

            $table->index(['routine_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routine_windows');
    }
};
