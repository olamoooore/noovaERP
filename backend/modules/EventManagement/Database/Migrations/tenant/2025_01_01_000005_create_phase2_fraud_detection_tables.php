<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for Phase 2 Fraud Detection System.
     * Creates tables for booking velocity tracking, IP restrictions, and fraud detection rules.
     */
    public function up(): void
    {
        // Booking velocity logs for monitoring user behavior
        Schema::create('booking_velocity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->onDelete('cascade');
            $table->string('ip_address', 45); // Support IPv6
            $table->string('user_agent')->nullable();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->integer('tickets_requested');
            $table->enum('action_type', ['booking_attempt', 'booking_success', 'booking_failed', 'booking_cancelled']);
            $table->string('session_id')->nullable();
            $table->json('request_metadata')->nullable(); // Browser fingerprint, etc.
            $table->timestamp('created_at');

            // Indexes for performance and fraud detection queries
            $table->index(['tenant_id', 'customer_id', 'created_at']);
            $table->index(['tenant_id', 'ip_address', 'created_at']);
            $table->index(['tenant_id', 'event_id', 'created_at']);
            $table->index(['tenant_id', 'action_type', 'created_at']);
            $table->index(['session_id', 'created_at']);
        });

        // IP restrictions and allowlist management
        Schema::create('ip_restrictions', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('ip_address', 45);
            $table->string('ip_range')->nullable(); // CIDR notation for ranges
            $table->enum('restriction_type', ['blocked', 'allowed', 'rate_limited', 'flagged']);
            $table->text('reason')->nullable();
            $table->integer('violation_count')->default(0);
            $table->timestamp('first_violation_at')->nullable();
            $table->timestamp('last_violation_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable(); // Additional restriction data
            $table->timestamps();
            $table->softDeletes();

            // Indexes for IP lookup performance
            $table->index(['tenant_id', 'ip_address', 'is_active']);
            $table->index(['tenant_id', 'restriction_type', 'is_active']);
            $table->index(['tenant_id', 'expires_at']);
        });

        // Fraud detection rules configuration
        Schema::create('fraud_detection_rules', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('rule_name');
            $table->string('rule_type'); // velocity, pattern, threshold, etc.
            $table->text('description')->nullable();
            $table->json('conditions'); // Rule conditions and thresholds
            $table->json('actions'); // Actions to take when rule is triggered
            $table->integer('priority')->default(0); // Higher = more important
            $table->boolean('is_active')->default(true);
            $table->integer('trigger_count')->default(0);
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes for rule evaluation
            $table->index(['tenant_id', 'rule_type', 'is_active']);
            $table->index(['tenant_id', 'priority', 'is_active']);
        });

        // Identity verification tracking
        Schema::create('identity_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            $table->enum('verification_level', ['none', 'basic', 'enhanced', 'full']);
            $table->enum('verification_status', ['pending', 'in_progress', 'verified', 'failed', 'expired']);
            $table->string('verification_method')->nullable(); // email, phone, document, etc.
            $table->json('verification_data')->nullable(); // Encrypted verification details
            $table->timestamp('initiated_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->integer('attempt_count')->default(1);
            $table->timestamps();

            // Indexes for verification lookups
            $table->index(['tenant_id', 'customer_id', 'verification_status']);
            $table->index(['tenant_id', 'verification_level', 'verification_status']);
            $table->index(['tenant_id', 'expires_at']);
        });

        // Ticket transfer limitations tracking
        Schema::create('ticket_transfer_logs', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('ticket_id')->constrained('tickets')->onDelete('cascade');
            $table->foreignId('from_customer_id')->constrained('customers')->onDelete('cascade');
            $table->foreignId('to_customer_id')->constrained('customers')->onDelete('cascade');
            $table->enum('transfer_type', ['sale', 'gift', 'exchange', 'refund']);
            $table->decimal('transfer_price', 10, 2)->nullable();
            $table->enum('transfer_status', ['pending', 'approved', 'rejected', 'completed', 'cancelled']);
            $table->text('rejection_reason')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable(); // Additional transfer data
            $table->timestamps();

            // Indexes for transfer tracking
            $table->index(['tenant_id', 'from_customer_id', 'created_at']);
            $table->index(['tenant_id', 'to_customer_id', 'created_at']);
            $table->index(['tenant_id', 'transfer_status']);
            $table->index(['tenant_id', 'ticket_id']);
        });

        // Suspicious activity alerts
        Schema::create('fraud_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('fraud_detection_rule_id')->constrained('fraud_detection_rules')->onDelete('cascade');
            $table->string('alert_type'); // velocity_exceeded, suspicious_pattern, etc.
            $table->enum('severity', ['low', 'medium', 'high', 'critical']);
            $table->enum('status', ['open', 'investigating', 'resolved', 'false_positive']);
            $table->string('entity_type'); // customer, ip_address, booking, etc.
            $table->string('entity_id'); // ID of the flagged entity
            $table->text('description');
            $table->json('evidence'); // Data that triggered the alert
            $table->json('actions_taken')->nullable(); // Automated actions performed
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('set null');
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // Indexes for alert management
            $table->index(['tenant_id', 'status', 'severity']);
            $table->index(['tenant_id', 'alert_type', 'created_at']);
            $table->index(['tenant_id', 'entity_type', 'entity_id']);
            $table->index(['assigned_to', 'status']);
        });

        // Manual review queue for suspicious bookings
        Schema::create('manual_review_queue', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('booking_id')->constrained('bookings')->onDelete('cascade');
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            $table->enum('review_reason', ['velocity_exceeded', 'suspicious_pattern', 'high_risk_customer', 'manual_flag', 'identity_verification_required']);
            $table->enum('priority', ['low', 'medium', 'high', 'urgent']);
            $table->enum('status', ['pending', 'in_review', 'approved', 'rejected', 'escalated']);
            $table->json('risk_factors'); // Factors that triggered manual review
            $table->decimal('risk_score', 5, 2)->nullable(); // 0-100 risk score
            $table->foreignId('assigned_reviewer')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('assigned_at')->nullable();
            $table->text('reviewer_notes')->nullable();
            $table->json('review_actions')->nullable(); // Actions taken during review
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // Indexes for review queue management
            $table->index(['tenant_id', 'status', 'priority']);
            $table->index(['tenant_id', 'review_reason', 'status']);
            $table->index(['assigned_reviewer', 'status']);
            $table->index(['tenant_id', 'risk_score']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('manual_review_queue');
        Schema::dropIfExists('fraud_alerts');
        Schema::dropIfExists('ticket_transfer_logs');
        Schema::dropIfExists('identity_verifications');
        Schema::dropIfExists('fraud_detection_rules');
        Schema::dropIfExists('ip_restrictions');
        Schema::dropIfExists('booking_velocity_logs');
    }
};