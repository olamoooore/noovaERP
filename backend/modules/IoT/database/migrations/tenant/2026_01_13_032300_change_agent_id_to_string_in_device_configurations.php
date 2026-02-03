<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('device_configurations', function (Blueprint $table) {
            // Change agent_id from uuid to string to match iot_devices table
            $table->string('agent_id', 100)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_configurations', function (Blueprint $table) {
            // Revert back to uuid
            $table->uuid('agent_id')->change();
        });
    }
};
