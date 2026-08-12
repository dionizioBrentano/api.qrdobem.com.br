<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panic_events', function (Blueprint $table) {
            $table->dropForeign(['space_id']);
        });

        Schema::table('panic_events', function (Blueprint $table) {
            $table->unsignedBigInteger('space_id')->nullable()->change();
            $table->foreign('space_id')->references('id')->on('spaces')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('panic_events', function (Blueprint $table) {
            $table->dropForeign(['space_id']);
        });

        Schema::table('panic_events', function (Blueprint $table) {
            $table->unsignedBigInteger('space_id')->nullable(false)->change();
            $table->foreign('space_id')->references('id')->on('spaces')->cascadeOnDelete();
        });
    }
};
