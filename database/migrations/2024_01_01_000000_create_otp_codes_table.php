<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('firebase_uid');
            $table->string('email');
            $table->string('code', 6);
            $table->boolean('is_used')->default(false);
            $table->timestamp('expires_at');
            $table->timestamps();
            
            $table->index('firebase_uid');
        });
    }

    public function down()
    {
        Schema::dropIfExists('otp_codes');
    }
};
