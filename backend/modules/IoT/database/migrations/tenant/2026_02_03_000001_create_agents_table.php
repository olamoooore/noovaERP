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
        Schema::create('agents', function (Blueprint $table) {
            $table->string('id')->primary(); // agent-{uuid}
            $table->string('name');
            $table->string('api_token')->unique();
            $table->enum('status', ['online', 'offline', 'error'])->default('offline');
            $table->timestamp('last_heartbeat')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('last_heartbeat');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
