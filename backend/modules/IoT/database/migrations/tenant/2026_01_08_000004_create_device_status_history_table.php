<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This table stores historical device status changes for monitoring and analytics.
     */
    public function up(): void
    {
        Schema::create('device_status_history', function (Blueprint $table) {
            $table->id();
            $table->uuid('device_id');
            $table->uuid('agent_id');

            // Status information
            $table->string('status', 20); // ready, busy, offline, error
            $table->text('message')->nullable();
            $table->string('error_code', 50)->nullable();

            $table->timestamp('recorded_at');

            // Indexes for performance
            $table->index(['device_id', 'recorded_at']);
            $table->index('agent_id');
            $table->index('status');

            // Foreign keys
            // $table->foreign('device_id')->references('id')->on('device_configurations')->onDelete('cascade');
            // $table->foreign('agent_id')->references('id')->on('agents')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_status_history');
    }
};
