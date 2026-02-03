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
        Schema::table('discovered_devices', function (Blueprint $table) {
            // Drop the unique constraint first
            $table->dropUnique('unique_device_per_agent');
            
            // Change agent_id from uuid to string
            $table->string('agent_id', 100)->change();
            
            // Recreate the unique constraint
            $table->unique(['agent_id', 'serial_number', 'port'], 'unique_device_per_agent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discovered_devices', function (Blueprint $table) {
            // Drop the unique constraint first
            $table->dropUnique('unique_device_per_agent');
            
            // Change back to uuid
            $table->uuid('agent_id')->change();
            
            // Recreate the unique constraint
            $table->unique(['agent_id', 'serial_number', 'port'], 'unique_device_per_agent');
        });
    }
};
