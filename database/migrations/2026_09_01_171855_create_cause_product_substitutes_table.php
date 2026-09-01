<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cause_product_substitutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('cause_products')->cascadeOnDelete();
            $table->foreignId('substitute_id')->constrained('cause_products')->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            $table->enum('reason', ['falta', 'preco', 'finalidade']);
            $table->decimal('qty_equivalent', 10, 2)->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'substitute_id']);
        });

        // Garantir que o produto não seja substituto dele mesmo
        DB::statement('ALTER TABLE cause_product_substitutes ADD CONSTRAINT chk_product_not_substitute CHECK (product_id != substitute_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cause_product_substitutes');
    }
};
