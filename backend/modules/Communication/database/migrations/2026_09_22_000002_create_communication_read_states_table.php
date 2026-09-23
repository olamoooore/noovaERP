<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #86: per-user read tracking for communication messages (unread
 * counters, mark-as-read / mark-all-read, unread filtering).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('communication_read_states')) {
            return;
        }

        Schema::create('communication_read_states', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('message_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('read_at');
            $table->timestamps();

            $table->foreign('message_id')->references('id')->on('communication_messages')->onDelete('cascade');
            $table->unique(['message_id', 'user_id'], 'communication_read_unique');
            $table->index(['user_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_read_states');
    }
};
