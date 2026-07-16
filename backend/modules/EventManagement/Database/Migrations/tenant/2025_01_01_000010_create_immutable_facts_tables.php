<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for immutable facts tables.
     * These tables store booking-time snapshots to prevent analytics drift.
     */
    public function up(): void
    {
        // Create booking_facts table for immutable booking-time snapshots
        Schema::create('booking_facts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->json('event_snapshot')->comment('Complete event data at booking time');
            $table->json('ticket_type_snapshot')->comment('Ticket type data at booking time');
            $table->json('artist_snapshot')->nullable()->comment('Artist/group data at booking time');
            $table->json('pricing_snapshot')->comment('Pricing and discount data at booking time');
            $table->json('tier_snapshot')->nullable()->comment('Customer tier data at booking time');
            $table->json('metadata')->nullable()->comment('Additional booking context');
            $table->timestamp('created_at')->useCurrent();

            // Foreign key constraints
            $table->foreign('booking_id')->references('id')->on('bookings')->onDelete('cascade');

            // Indexes for performance and tenant isolation
            $table->index('booking_id', 'idx_booking_facts_booking_id');
            $table->index('created_at', 'idx_booking_facts_created_at');

            // Ensure one fact record per booking
            $table->unique('booking_id', 'unique_booking_facts');
        });

        // Create analytics_facts table for immutable event-driven analytics
        Schema::create('analytics_facts', function (Blueprint $table) {
            $table->id();
            $table->enum('fact_type', [
                'booking_created',
                'payment_confirmed',
                'ticket_issued',
                'check_in',
                'cancellation',
                'refund',
                'transfer',
            ])->comment('Type of analytics fact');
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('event_id');
            $table->json('fact_data')->comment('Immutable fact data for analytics');
            $table->json('calculated_metrics')->nullable()->comment('Pre-calculated metrics');
            $table->timestamp('fact_timestamp')->useCurrent();
            $table->string('idempotency_key', 255)->unique()->comment('Ensures exactly-once processing');

            // Foreign key constraints
            $table->foreign('booking_id')->references('id')->on('bookings')->onDelete('cascade');
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');

            // Indexes for analytics queries and tenant isolation
            $table->index(['fact_type', 'fact_timestamp'], 'idx_analytics_facts_type_time');
            $table->index(['event_id', 'fact_timestamp'], 'idx_analytics_facts_event_time');
            $table->index(['booking_id', 'fact_timestamp'], 'idx_analytics_facts_booking_time');
            $table->index('fact_timestamp', 'idx_analytics_facts_timestamp');
            $table->index('idempotency_key', 'idx_analytics_facts_idempotency');
        });

        // Create fact_reconciliation_log for tracking data consistency
        Schema::create('fact_reconciliation_log', function (Blueprint $table) {
            $table->id();
            $table->string('reconciliation_type', 100)->comment('Type of reconciliation check');
            $table->unsignedBigInteger('event_id')->nullable();
            $table->date('reconciliation_date');
            $table->json('source_data_summary')->comment('Summary of source data');
            $table->json('fact_data_summary')->comment('Summary of fact data');
            $table->json('discrepancies')->nullable()->comment('Any discrepancies found');
            $table->enum('status', ['passed', 'failed', 'warning'])->default('passed');
            $table->text('notes')->nullable();
            $table->timestamp('reconciled_at')->useCurrent();

            // Foreign key constraints
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');

            // Indexes for reconciliation queries
            $table->index(['reconciliation_type', 'reconciliation_date'], 'idx_reconciliation_type_date');
            $table->index(['event_id', 'reconciliation_date'], 'idx_reconciliation_event_date');
            $table->index(['status', 'reconciled_at'], 'idx_reconciliation_status_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fact_reconciliation_log');
        Schema::dropIfExists('analytics_facts');
        Schema::dropIfExists('booking_facts');
    }
};
