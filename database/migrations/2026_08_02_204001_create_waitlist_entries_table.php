<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('interest');
            $table->timestamp('created_at')->nullable();

            $table->unique(['email', 'interest']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('waitlist_entries');
    }
};
