<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entity_devices', function (Blueprint $table) {
            $table->string('token_hash', 64)->nullable()->index();
            $table->timestamp('token_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('entity_devices', function (Blueprint $table) {
            $table->dropIndex(['token_hash']);
            $table->dropColumn(['token_hash', 'token_expires_at']);
        });
    }
};
