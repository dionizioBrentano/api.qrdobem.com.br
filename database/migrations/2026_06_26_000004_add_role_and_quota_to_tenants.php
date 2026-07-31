<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->enum('role', ['superadmin', 'pharmacy', 'petshop', 'ngo', 'association'])->default('ngo')->after('firebase_uid');
            $table->integer('qr_quota')->default(100)->after('role'); // Saldo de QRs permitidos
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['role', 'qr_quota']);
        });
    }
};
