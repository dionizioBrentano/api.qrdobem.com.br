<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adventure_events', function (Blueprint $table) {
            $table->string('reason', 30)->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->string('device_id', 64)->nullable();

            $table->index(['entity_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('adventure_events', function (Blueprint $table) {
            $table->dropIndex(['entity_id', 'status']);
            
            $table->dropColumn([
                'reason',
                'requested_at',
                'responded_at',
                'device_id'
            ]);
        });
    }
};
