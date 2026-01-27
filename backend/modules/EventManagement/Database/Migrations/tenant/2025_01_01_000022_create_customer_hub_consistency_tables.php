<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Create Customer Hub Consistency Tables - Task 10.1
 * 
 * Creates comprehensive customer hub consistency database schema with ticket
 * status tracking, single source of truth for ticket validity, status change
 * audit trails, and synchronization support across all customer touchpoints.
 * 
 * Requirements: 26.1, 26.2
 */
class CreateCustomerHubConsistencyTables extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Comprehensive ticket status log for single source of truth
        Schema::create('ticket_status_log', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('ticket_id')->index();
            $table->bigInteger('booking_id')->index();
            $table->bigInteger('customer_id')->index();
            $table->bigInteger('event_id')->index();
            $table->enum('status', [
                'pending_payment',
                'payment_confirmed',
                'ticket_issued',
                'ready_for_delivery',
                'delivered',
                'checked_in',
                'used',
                'cancelled',
                'refunded',
                'expired',
                'suspended',
                'invalid'
            ])->index();
            $table->enum('previous_status', [
                'pending_payment',
                'payment_confirmed',
                'ticket_issued',
                'ready_for_delivery',
                'delivered',
                'checked_in',
                'used',
                'cancelled',
                'refunded',
                'expired',
                'suspended',
                'invalid'
            ])->nullable()->index();
            $table->timestamp('status_changed_at')->index();
            $table->bigInteger('changed_by_staff_id')->nullable()->index();
            $table->string('change_reason', 255)->nullable();
            $table->enum('change_source', [
                'customer_portal',
                'admin_console',
                'api_integration',
                'automated_system',
                'payment_processor',
                'check_in_system',
                'refund_system',
                'notification_system'
            ])->index();
            $table->json('status_metadata'); // Additional status-specific data
            $table->json('change_context'); // Context of the status change
            $table->string('correlation_id', 255)->nullable()->index(); // For tracking related changes
            $table->boolean('is_current_status')->default(true)->index();
            $table->timestamp('effective_until')->nullable()->index(); // For temporary statuses
            $table->json('validation_data')->nullable(); // Validation checks performed
            $table->string('external_reference', 255)->nullable()->index(); // External system reference
            $table->timestamps();
            
            $table->index(['ticket_id', 'status_changed_at']);
            $table->index(['customer_id', 'status']);
            $table->index(['event_id', 'status']);
            $table->index(['is_current_status', 'status']);
            $table->index(['change_source', 'status_changed_at']);
            $table->index(['correlation_id', 'status_changed_at']);
        });

        // Ticket validity and authenticity tracking
        Schema::create('ticket_validity_registry', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('ticket_id')->unique()->index();
            $table->string('ticket_hash', 255)->unique()->index(); // Cryptographic hash for authenticity
            $table->string('qr_code_data', 500)->nullable(); // QR code content
            $table->string('barcode_data', 255)->nullable(); // Barcode content
            $table->enum('validity_status', [
                'valid',
                'invalid',
                'suspended',
                'revoked',
                'expired',
                'pending_validation'
            ])->default('valid')->index();
            $table->timestamp('valid_from')->index();
            $table->timestamp('valid_until')->nullable()->index();
            $table->json('validity_conditions'); // Conditions for ticket validity
            $table->json('security_features'); // Security features and checksums
            $table->integer('resend_count')->default(0);
            $table->timestamp('last_resent_at')->nullable();
            $table->bigInteger('last_resent_by')->nullable();
            $table->json('resend_history')->nullable(); // History of resend operations
            $table->boolean('is_transferable')->default(true)->index();
            $table->bigInteger('current_owner_id')->nullable()->index();
            $table->json('transfer_history')->nullable(); // History of ownership transfers
            $table->string('delivery_method', 100)->nullable()->index(); // email, sms, app, etc.
            $table->json('delivery_status')->nullable(); // Delivery confirmation details
            $table->timestamps();
            
            $table->index(['validity_status', 'valid_from']);
            $table->index(['valid_until', 'validity_status']);
            $table->index(['current_owner_id', 'validity_status']);
        });

        // Customer touchpoint synchronization tracking
        Schema::create('customer_touchpoint_sync', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('customer_id')->index();
            $table->bigInteger('ticket_id')->nullable()->index();
            $table->bigInteger('booking_id')->nullable()->index();
            $table->string('touchpoint_type', 100)->index(); // mobile_app, web_portal, email, sms, etc.
            $table->string('touchpoint_id', 255)->index(); // Specific touchpoint identifier
            $table->enum('sync_status', [
                'pending',
                'in_progress',
                'completed',
                'failed',
                'partial',
                'conflict'
            ])->default('pending')->index();
            $table->timestamp('sync_initiated_at')->index();
            $table->timestamp('sync_completed_at')->nullable();
            $table->json('sync_data'); // Data being synchronized
            $table->json('sync_result')->nullable(); // Result of synchronization
            $table->json('error_details')->nullable(); // Error information if failed
            $table->integer('retry_count')->default(0);
            $table->timestamp('last_retry_at')->nullable();
            $table->string('correlation_id', 255)->nullable()->index();
            $table->boolean('requires_manual_intervention')->default(false)->index();
            $table->json('conflict_resolution')->nullable(); // Conflict resolution data
            $table->timestamps();
            
            $table->index(['customer_id', 'sync_status']);
            $table->index(['touchpoint_type', 'sync_status']);
            $table->index(['sync_initiated_at', 'sync_status']);
            $table->index(['requires_manual_intervention', 'sync_status']);
        });

        // Customer notification tracking and delivery
        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('customer_id')->index();
            $table->bigInteger('ticket_id')->nullable()->index();
            $table->bigInteger('booking_id')->nullable()->index();
            $table->bigInteger('event_id')->nullable()->index();
            $table->string('notification_type', 100)->index(); // status_change, reminder, instruction, etc.
            $table->string('delivery_channel', 50)->index(); // email, sms, push, in_app
            $table->string('template_id', 100)->nullable()->index();
            $table->text('subject')->nullable();
            $table->text('content');
            $table->json('personalization_data')->nullable(); // Data used for personalization
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal')->index();
            $table->enum('delivery_status', [
                'pending',
                'queued',
                'sent',
                'delivered',
                'read',
                'failed',
                'bounced',
                'unsubscribed'
            ])->default('pending')->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->json('delivery_metadata')->nullable(); // Provider-specific delivery data
            $table->json('tracking_data')->nullable(); // Click tracking, etc.
            $table->integer('retry_count')->default(0);
            $table->timestamp('last_retry_at')->nullable();
            $table->string('external_message_id', 255)->nullable()->index();
            $table->json('error_details')->nullable();
            $table->timestamps();
            
            $table->index(['customer_id', 'delivery_status']);
            $table->index(['notification_type', 'delivery_status']);
            $table->index(['delivery_channel', 'delivery_status']);
            $table->index(['scheduled_at', 'delivery_status']);
            $table->index(['event_id', 'notification_type']);
        });

        // Customer hub session and activity tracking
        Schema::create('customer_hub_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 255)->unique()->index();
            $table->bigInteger('customer_id')->index();
            $table->string('device_type', 100)->nullable()->index(); // mobile, desktop, tablet
            $table->string('platform', 100)->nullable()->index(); // ios, android, web
            $table->string('app_version', 50)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->json('geolocation')->nullable(); // Location data if available
            $table->timestamp('session_started_at')->index();
            $table->timestamp('session_ended_at')->nullable();
            $table->timestamp('last_activity_at')->index();
            $table->enum('session_status', ['active', 'inactive', 'expired', 'terminated'])->default('active')->index();
            $table->json('session_data')->nullable(); // Session-specific data
            $table->integer('page_views')->default(0);
            $table->integer('actions_performed')->default(0);
            $table->json('activity_log')->nullable(); // Log of activities during session
            $table->timestamps();
            
            $table->index(['customer_id', 'session_status']);
            $table->index(['session_started_at', 'session_status']);
            $table->index(['last_activity_at', 'session_status']);
        });

        // Status change conflict resolution
        Schema::create('status_change_conflicts', function (Blueprint $table) {
            $table->id();
            $table->string('conflict_id', 255)->unique()->index();
            $table->bigInteger('ticket_id')->index();
            $table->bigInteger('customer_id')->index();
            $table->enum('conflict_type', [
                'concurrent_status_change',
                'invalid_status_transition',
                'touchpoint_inconsistency',
                'external_system_mismatch',
                'timing_conflict',
                'permission_violation'
            ])->index();
            $table->json('conflicting_changes'); // Details of conflicting changes
            $table->json('current_state'); // Current system state
            $table->json('proposed_changes'); // Proposed changes causing conflict
            $table->enum('resolution_strategy', [
                'last_write_wins',
                'first_write_wins',
                'merge_changes',
                'manual_resolution',
                'rollback_changes',
                'escalate_to_admin'
            ])->nullable()->index();
            $table->enum('conflict_status', [
                'detected',
                'analyzing',
                'resolved',
                'escalated',
                'ignored'
            ])->default('detected')->index();
            $table->bigInteger('resolved_by_staff_id')->nullable()->index();
            $table->timestamp('resolved_at')->nullable();
            $table->json('resolution_data')->nullable(); // Resolution details
            $table->text('resolution_notes')->nullable();
            $table->integer('resolution_priority')->default(5)->index(); // 1=highest, 10=lowest
            $table->boolean('requires_customer_notification')->default(false)->index();
            $table->timestamp('detected_at')->index();
            $table->timestamps();
            
            $table->index(['conflict_status', 'resolution_priority']);
            $table->index(['ticket_id', 'conflict_type']);
            $table->index(['customer_id', 'conflict_status']);
            $table->index(['requires_customer_notification', 'conflict_status']);
        });

        // Customer hub configuration and preferences
        Schema::create('customer_hub_preferences', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('customer_id')->unique()->index();
            $table->json('notification_preferences'); // Channel preferences, frequency, etc.
            $table->json('display_preferences'); // UI preferences, language, timezone
            $table->json('privacy_settings'); // Privacy and data sharing preferences
            $table->boolean('email_notifications_enabled')->default(true)->index();
            $table->boolean('sms_notifications_enabled')->default(true)->index();
            $table->boolean('push_notifications_enabled')->default(true)->index();
            $table->string('preferred_language', 10)->default('en')->index();
            $table->string('timezone', 50)->default('UTC');
            $table->enum('communication_frequency', [
                'immediate',
                'daily_digest',
                'weekly_digest',
                'minimal'
            ])->default('immediate')->index();
            $table->json('accessibility_settings')->nullable(); // Accessibility preferences
            $table->boolean('marketing_communications')->default(false)->index();
            $table->timestamp('preferences_updated_at')->nullable();
            $table->timestamps();
        });

        // Real-time status synchronization queue
        Schema::create('status_sync_queue', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('ticket_id')->index();
            $table->bigInteger('customer_id')->index();
            $table->string('sync_operation', 100)->index(); // status_change, resend_ticket, etc.
            $table->json('operation_data'); // Data for the sync operation
            $table->json('target_touchpoints'); // Which touchpoints to sync to
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal')->index();
            $table->enum('sync_status', [
                'pending',
                'processing',
                'completed',
                'failed',
                'partial'
            ])->default('pending')->index();
            $table->timestamp('queued_at')->index();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('sync_results')->nullable(); // Results from each touchpoint
            $table->json('error_details')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->string('correlation_id', 255)->nullable()->index();
            $table->timestamps();
            
            $table->index(['sync_status', 'priority']);
            $table->index(['queued_at', 'sync_status']);
            $table->index(['customer_id', 'sync_status']);
            $table->index(['next_retry_at', 'sync_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('status_sync_queue');
        Schema::dropIfExists('customer_hub_preferences');
        Schema::dropIfExists('status_change_conflicts');
        Schema::dropIfExists('customer_hub_sessions');
        Schema::dropIfExists('customer_notifications');
        Schema::dropIfExists('customer_touchpoint_sync');
        Schema::dropIfExists('ticket_validity_registry');
        Schema::dropIfExists('ticket_status_log');
    }
}