<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('cpf')->nullable();
            $table->date('dob')->nullable(); // Date of Birth
            $table->string('phone')->nullable();
            $table->enum('profile_status', ['incomplete', 'pending_approval', 'active'])->default('incomplete');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['cpf', 'dob', 'phone', 'profile_status']);
        });
    }
};
