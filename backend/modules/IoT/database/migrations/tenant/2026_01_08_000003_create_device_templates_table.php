<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This table stores device templates for quick configuration.
     * Templates provide pre-configured settings for common device types.
     */
    public function up(): void
    {
        Schema::create('device_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100); // e.g., 'Gprinter GP-D320FX Label Printer'
            $table->string('device_type', 20); // printer, scanner, terminal
            $table->string('driver_type', 50); // label-printer-raw, receipt-printer-raw, etc.
            
            // Hardware matching for auto-suggestion
            $table->string('vendor_id', 10)->nullable();
            $table->string('product_id', 10)->nullable();
            $table->string('manufacturer', 100)->nullable();
            $table->string('model', 100)->nullable();
            
            // Default configuration
            $table->json('default_config'); // Default driver configuration
            $table->json('capabilities');   // ['print_label', 'get_status']
            
            // Metadata
            $table->text('description')->nullable();
            $table->string('icon', 255)->nullable();
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index('device_type');
            $table->index(['vendor_id', 'product_id']);
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_templates');
    }
};
