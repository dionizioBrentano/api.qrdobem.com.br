<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cause_product_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('cause_products')->cascadeOnDelete();
            $table->string('attr_key');
            $table->string('attr_value');
            $table->enum('significance', [
                'financeiro',
                'identidade',
                'apresentacao',
                'comercial',
                'logistica',
                'uso'
            ]);
            $table->timestamps();

            $table->unique(['product_id', 'attr_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cause_product_attributes');
    }
};
