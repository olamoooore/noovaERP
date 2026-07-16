<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for enhanced capacity management.
     * Implements concurrency-proof capacity management with reservations.
     */
    public function up(): void
    {
        // Add capacity versioning and reservation tracking to events table
        Schema::table('events', function (Blueprint $table) {
            $table->integer('capacity_version')->default(1)->after('capacity');
            $table->integer('reserved_capacity')->default(0)->after('capacity_version');
            $table->integer('confirmed_capacity')->default(0)->after('reserved_capacity');
            $table->timestamp('capacity_last_updated')->nullable()->after('confirmed_capacity');

            // Index for capacity queries
            $table->index(['capacity_version', 'reserved_capacity'], 'idx_events_capacity_version');
        });

        // Create capacity_reservations table for temporary capacity holds
        Schema::create('capacity_reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('booking_id')->nullable()->comment('Booking ID when reservation is confirmed');
            $table->unsignedBigInteger('ticket_type_id')->nullable();
            $table->integer('quantity')->comment('Number of tickets reserved');
            $table->string('reservation_token', 255)->unique()->comment('Unique token for this reservation');
            $table->enum('status', ['active', 'confirmed', 'expired', 'cancelled'])->default('active');
            $table->timestamp('reservation_expires_at')->comment('When this reservation expires');
            $table->string('customer_session_id', 255)->nullable()->comment('Customer session for tracking');
            $table->string('ip_address', 45)->nullable();
            $table->json('reservation_metadata')->nullable()->comment('Additional reservation context');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('expired_at')->nullable();

            // Foreign key constraints
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('booking_id')->references('id')->on('bookings')->onDelete('set null');
            $table->foreign('ticket_type_id')->references('id')->on('event_ticket_types')->onDelete('set null');

            // Indexes for efficient capacity checking
            $table->index(['event_id', 'status', 'reservation_expires_at'], 'idx_reservations_event_status_expires');
            $table->index(['status', 'reservation_expires_at'], 'idx_reservations_status_expires');
            $table->index(['booking_id'], 'idx_reservations_booking');
            $table->index(['customer_session_id', 'status'], 'idx_reservations_session_status');
            $table->index('reservation_token', 'idx_reservations_token');
        });

        // Create capacity_audit_log for tracking all capacity changes
        Schema::create('capacity_audit_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->enum('action', [
                'reservation_created',
                'reservation_confirmed',
                'reservation_expired',
                'reservation_cancelled',
                'capacity_updated',
                'overbooking_detected',
                'capacity_reconciled',
            ]);
            $table->integer('quantity_change')->comment('Change in capacity (+/-)');
            $table->integer('capacity_before')->comment('Capacity state before change');
            $table->integer('capacity_after')->comment('Capacity state after change');
            $table->integer('reserved_before')->comment('Reserved capacity before change');
            $table->integer('reserved_after')->comment('Reserved capacity after change');
            $table->unsignedBigInteger('related_reservation_id')->nullable();
            $table->unsignedBigInteger('related_booking_id')->nullable();
            $table->string('actor_type', 50)->comment('user, system, job, etc.');
            $table->string('actor_id', 255)->nullable();
            $table->text('notes')->nullable();
            $table->json('audit_metadata')->nullable();
            $table->timestamp('logged_at')->useCurrent();

            // Foreign key constraints
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('related_reservation_id')->references('id')->on('capacity_reservations')->onDelete('set null');
            $table->foreign('related_booking_id')->references('id')->on('bookings')->onDelete('set null');

            // Indexes for audit queries
            $table->index(['event_id', 'logged_at'], 'idx_capacity_audit_event_time');
            $table->index(['action', 'logged_at'], 'idx_capacity_audit_action_time');
            $table->index(['actor_type', 'actor_id'], 'idx_capacity_audit_actor');
        });

        // Create capacity_reconciliation_log for tracking reconciliation results
        Schema::create('capacity_reconciliation_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->integer('expected_available')->comment('Expected available capacity');
            $table->integer('actual_available')->comment('Actual calculated available capacity');
            $table->integer('reserved_count')->comment('Count of active reservations');
            $table->integer('confirmed_count')->comment('Count of confirmed bookings');
            $table->integer('discrepancy')->comment('Difference between expected and actual');
            $table->enum('status', ['passed', 'warning', 'critical'])->comment('Reconciliation result');
            $table->text('resolution_notes')->nullable();
            $table->json('reconciliation_data')->nullable()->comment('Detailed reconciliation data');
            $table->timestamp('reconciled_at')->useCurrent();

            // Foreign key constraints
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');

            // Indexes for reconciliation queries
            $table->index(['event_id', 'reconciled_at'], 'idx_capacity_reconciliation_event_time');
            $table->index(['status', 'reconciled_at'], 'idx_capacity_reconciliation_status_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('capacity_reconciliation_log');
        Schema::dropIfExists('capacity_audit_log');
        Schema::dropIfExists('capacity_reservations');

        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('idx_events_capacity_version');
            $table->dropColumn([
                'capacity_version',
                'reserved_capacity',
                'confirmed_capacity',
                'capacity_last_updated',
            ]);
        });
    }
};
