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
        Schema::create('iot_device_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('iot_device_id')->constrained()->onDelete('cascade');
            $table->string('action');                       // print, payment, scan
            $table->string('status');                       // success, failed, pending
            $table->json('request_data')->nullable();
            $table->json('response_data')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('created_at');

            // Indexes
            $table->index(['iot_device_id', 'created_at']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('iot_device_logs');
    }
};
