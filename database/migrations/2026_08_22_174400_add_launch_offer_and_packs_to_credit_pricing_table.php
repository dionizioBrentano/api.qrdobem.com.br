<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_pricing', function (Blueprint $table) {
            $table->decimal('adventure_yearly_price', 10, 2)->nullable();
            $table->integer('family_pack_qty')->nullable();
            $table->decimal('family_pack_price', 10, 2)->nullable();
            $table->boolean('launch_offer_enabled')->nullable();
            $table->decimal('launch_offer_discount_percent', 5, 2)->nullable();
            $table->date('launch_offer_ends_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('credit_pricing', function (Blueprint $table) {
            $table->dropColumn([
                'adventure_yearly_price',
                'family_pack_qty',
                'family_pack_price',
                'launch_offer_enabled',
                'launch_offer_discount_percent',
                'launch_offer_ends_at',
            ]);
        });
    }
};
