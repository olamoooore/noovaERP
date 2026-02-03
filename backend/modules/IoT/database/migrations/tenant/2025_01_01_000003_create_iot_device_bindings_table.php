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
        Schema::create('iot_device_bindings', function (Blueprint $table) {
            $table->id();
            $table->string('station_id');                   // e.g., "pos-counter-1"
            $table->foreignId('iot_device_id')->constrained()->onDelete('cascade');
            $table->string('binding_type');                 // default_printer, default_terminal, default_scanner
            $table->timestamps();
            
            // Unique constraint: one device per binding type per station
            $table->unique(['station_id', 'binding_type']);
            
            // Indexes
            $table->index('station_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('iot_device_bindings');
    }
};
