<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This table stores devices discovered by agents but not yet configured.
     * Agents automatically scan for USB/Serial/HID devices and report them here.
     */
    public function up(): void
    {
        Schema::create('discovered_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('agent_id');
            
            // Device hardware information
            $table->string('vendor_id', 10)->nullable(); // e.g., '0x0fe6'
            $table->string('product_id', 10)->nullable(); // e.g., '0x811e'
            $table->string('manufacturer', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->string('port', 50)->nullable(); // e.g., 'USB001', 'COM10'
            $table->string('connection_type', 20); // usb, serial, hid
            
            // Status tracking
            $table->string('status', 20)->default('discovered'); // discovered, configured, ignored
            $table->uuid('configured_device_id')->nullable(); // Link to device_configurations
            
            $table->timestamp('discovered_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['agent_id', 'status']);
            $table->index(['vendor_id', 'product_id']);
            
            // Unique constraint: one device per agent/serial/port combination
            $table->unique(['agent_id', 'serial_number', 'port'], 'unique_device_per_agent');
            
            // Foreign keys
            // $table->foreign('agent_id')->references('id')->on('agents')->onDelete('cascade');
            // $table->foreign('configured_device_id')->references('id')->on('device_configurations')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discovered_devices');
    }
};
