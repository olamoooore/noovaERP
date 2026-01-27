<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for outbox pattern tables.
     * Implements transactional outbox pattern for exactly-once side effects processing.
     */
    public function up(): void
    {
        // Create outbox_events table for transactional outbox pattern
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->string('aggregate_id', 255)->comment('ID of the aggregate that generated the event');
            $table->string('aggregate_type', 100)->comment('Type of aggregate (booking, event, etc.)');
            $table->string('event_type', 100)->comment('Type of event (booking.created, payment.confirmed, etc.)');
            $table->json('event_data')->comment('Event payload data');
            $table->string('idempotency_key', 255)->unique()->comment('Unique key for exactly-once processing');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->integer('retry_count')->default(0);
            $table->integer('max_retries')->default(3);
            $table->timestamp('next_retry_at')->nullable()->comment('When to retry if failed');
            $table->text('error_message')->nullable()->comment('Last error message if failed');
            $table->json('metadata')->nullable()->comment('Additional event metadata');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('processed_at')->nullable()->comment('When event was successfully processed');
            
            // Indexes for efficient processing
            $table->index(['status', 'next_retry_at'], 'idx_outbox_status_retry');
            $table->index(['aggregate_type', 'aggregate_id'], 'idx_outbox_aggregate');
            $table->index(['event_type', 'created_at'], 'idx_outbox_event_type_time');
            $table->index('idempotency_key', 'idx_outbox_idempotency');
            $table->index('created_at', 'idx_outbox_created_at');
        });

        // Create outbox_event_consumers table for tracking consumer processing
        Schema::create('outbox_event_consumers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('outbox_event_id');
            $table->string('consumer_name', 100)->comment('Name of the consumer service');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->integer('retry_count')->default(0);
            $table->integer('max_retries')->default(3);
            $table->timestamp('next_retry_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('processing_metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            
            // Foreign key constraints
            $table->foreign('outbox_event_id')->references('id')->on('outbox_events')->onDelete('cascade');
            
            // Indexes for consumer processing
            $table->index(['consumer_name', 'status'], 'idx_consumer_name_status');
            $table->index(['outbox_event_id', 'consumer_name'], 'idx_event_consumer');
            $table->index(['status', 'next_retry_at'], 'idx_consumer_status_retry');
            
            // Ensure one record per event per consumer
            $table->unique(['outbox_event_id', 'consumer_name'], 'unique_event_consumer');
        });

        // Create outbox_processing_log for audit and monitoring
        Schema::create('outbox_processing_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('outbox_event_id');
            $table->string('consumer_name', 100)->nullable();
            $table->enum('action', ['created', 'processing_started', 'processing_completed', 'processing_failed', 'retry_scheduled'])->comment('Processing action');
            $table->text('message')->nullable()->comment('Log message');
            $table->json('context')->nullable()->comment('Additional context data');
            $table->timestamp('logged_at')->useCurrent();
            
            // Foreign key constraints
            $table->foreign('outbox_event_id')->references('id')->on('outbox_events')->onDelete('cascade');
            
            // Indexes for log queries
            $table->index(['outbox_event_id', 'logged_at'], 'idx_log_event_time');
            $table->index(['consumer_name', 'action'], 'idx_log_consumer_action');
            $table->index('logged_at', 'idx_log_logged_at');
        });

        // Create outbox_consumer_checkpoints for tracking consumer progress
        Schema::create('outbox_consumer_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->string('consumer_name', 100)->unique();
            $table->unsignedBigInteger('last_processed_event_id')->default(0);
            $table->timestamp('last_processed_at')->nullable();
            $table->json('consumer_metadata')->nullable()->comment('Consumer-specific metadata');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            
            // Indexes
            $table->index('consumer_name', 'idx_checkpoint_consumer');
            $table->index('last_processed_event_id', 'idx_checkpoint_last_event');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outbox_consumer_checkpoints');
        Schema::dropIfExists('outbox_processing_log');
        Schema::dropIfExists('outbox_event_consumers');
        Schema::dropIfExists('outbox_events');
    }
};