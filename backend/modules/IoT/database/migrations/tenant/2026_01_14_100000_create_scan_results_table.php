<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('device_id')->nullable();
            $table->string('agent_id');
            $table->text('barcode'); // Changed to text to support long QR/palm vein data
            $table->string('format')->nullable(); // EAN13, QR, CODE128, PALM_VEIN, etc.
            $table->timestamp('scanned_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('device_id');
            $table->index('agent_id');
            $table->index('scanned_at');
            $table->index('barcode');

            $table->foreign('device_id')
                ->references('id')
                ->on('device_configurations')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_results');
    }
};
