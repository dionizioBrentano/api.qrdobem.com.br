<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cause_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('purpose');
            $table->string('unit');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('platform_fee_pct', 5, 2)->nullable();
            $table->decimal('shipping_cost', 10, 2)->default(0);
            $table->decimal('other_costs', 10, 2)->default(0);
            $table->string('barcode')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('distributor')->nullable();
            $table->json('formula_keys')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cause_products');
    }
};
