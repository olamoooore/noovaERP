<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This table stores device configurations for the dynamic device management system.
     * Each configuration represents a device (printer, scanner, terminal) connected to an agent.
     */
    public function up(): void
    {
        Schema::create('device_configurations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('agent_id');
            $table->string('device_type', 20); // printer, scanner, terminal
            $table->string('driver_type', 50); // label-printer-raw, receipt-printer-raw, etc.
            $table->string('name', 100);
            $table->boolean('enabled')->default(true);
            
            // JSON columns for flexible configuration
            $table->json('connection_info'); // { printerName, vendorId, productId, port }
            $table->json('driver_config');   // Driver-specific settings
            $table->json('capabilities');    // ['print_label', 'get_status']
            $table->json('metadata')->nullable(); // { location, installDate, notes }
            
            // Status tracking
            $table->string('current_status', 20)->default('offline'); // ready, busy, offline, error
            $table->text('status_message')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes for performance
            $table->index(['agent_id', 'enabled']);
            $table->index('device_type');
            $table->index('current_status');
            
            // Foreign key (agents table should exist)
            // Note: Uncomment if agents table exists
            // $table->foreign('agent_id')->references('id')->on('agents')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_configurations');
    }
};