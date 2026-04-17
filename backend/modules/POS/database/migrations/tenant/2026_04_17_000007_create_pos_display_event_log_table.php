<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_display_event_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id')->nullable();
            $table->unsignedBigInteger('terminal_id')->nullable();
            $table->string('event_name', 100);
            $table->string('aggregate_type', 100)->nullable();
            $table->string('aggregate_id', 100)->nullable();
            $table->json('event_json')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            $table->index('session_id');
            $table->index(['session_id', 'occurred_at']);
            $table->index('event_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_display_event_log');
    }
};
