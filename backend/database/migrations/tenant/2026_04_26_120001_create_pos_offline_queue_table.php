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
        Schema::create('pos_offline_queue', function (Blueprint $table) {
            $table->id();

            // Queue item identification
            $table->string('queue_number')->unique();
            $table->string('operation_type')->comment('create_order, payment, void, refund, etc');
            $table->enum('status', ['pending', 'syncing', 'synced', 'failed', 'conflict'])->default('pending');

            // Terminal and session context
            $table->foreignId('terminal_id')->nullable()->constrained('pos_terminals')->onDelete('set null');
            $table->foreignId('session_id')->nullable()->constrained('pos_sessions')->onDelete('set null');

            // Payload
            $table->json('payload')->comment('The operation data to sync');
            $table->json('metadata')->nullable()->comment('Additional context, timestamps, etc');

            // Idempotency
            $table->string('idempotency_key', 120)->unique();

            // Sync tracking
            $table->integer('sync_attempts')->default(0);
            $table->timestamp('last_sync_attempt')->nullable();
            $table->text('sync_error')->nullable();
            $table->json('conflict_data')->nullable()->comment('Data causing conflict');

            // Result tracking
            $table->string('synced_entity_type')->nullable()->comment('PosOrder, PosPayment, etc');
            $table->unsignedBigInteger('synced_entity_id')->nullable();
            $table->index(['synced_entity_type', 'synced_entity_id'], 'offline_synced_entity_idx');

            // Audit
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('queued_at');
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            $table->index('queue_number');
            $table->index('status');
            $table->index('operation_type');
            $table->index('queued_at');
            $table->index('idempotency_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_offline_queue');
    }
};
