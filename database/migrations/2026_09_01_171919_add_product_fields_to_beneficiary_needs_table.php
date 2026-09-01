<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiary_needs', function (Blueprint $table) {
            $table->foreignId('cause_product_id')->nullable()->constrained('cause_products')->nullOnDelete();
            $table->decimal('quantity', 10, 2)->nullable();
            $table->boolean('accepts_substitute')->default(true);
            $table->date('period_starts_on')->nullable();
            $table->date('period_ends_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('beneficiary_needs', function (Blueprint $table) {
            $table->dropForeign(['cause_product_id']);
            $table->dropColumn([
                'cause_product_id',
                'quantity',
                'accepts_substitute',
                'period_starts_on',
                'period_ends_on'
            ]);
        });
    }
};
