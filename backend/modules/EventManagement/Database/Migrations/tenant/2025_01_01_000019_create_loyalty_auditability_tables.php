<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Create Loyalty Auditability Tables - Task 7.1
 *
 * Creates comprehensive loyalty auditability database schema including:
 * - eligibility_decisions table for access decision logging
 * - loyalty_ledger table for points transaction integrity
 * - loyalty_rule_versions table for rule versioning support
 * - eligibility_appeals table for decision appeal workflows
 * - loyalty_reconciliation_log table for balance reconciliation
 *
 * Requirements: 23.1, 23.2, 23.3
 */
class CreateLoyaltyAuditabilityTables extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Loyalty Rule Versions Table
        Schema::create('loyalty_rule_versions', function (Blueprint $table) {
            $table->id();
            $table->string('rule_name', 100)->index();
            $table->string('rule_category', 50)->index(); // tier_access, early_access, discount, points
            $table->text('rule_description');
            $table->json('rule_logic'); // JSON representation of rule logic
            $table->json('rule_parameters'); // Configurable parameters
            $table->string('version', 20)->index(); // Semantic versioning
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('effective_from')->index();
            $table->timestamp('effective_until')->nullable()->index();
            $table->string('created_by', 100);
            $table->text('change_reason')->nullable();
            $table->json('change_log')->nullable(); // What changed from previous version
            $table->timestamps();

            // Indexes for performance
            $table->index(['rule_name', 'version']);
            $table->index(['rule_category', 'is_active']);
            $table->index(['effective_from', 'effective_until']);

            // Unique constraint for active rules
            $table->unique(['rule_name', 'is_active'], 'unique_active_rule');
        });

        // 2. Eligibility Decisions Table
        Schema::create('eligibility_decisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('event_id')->nullable()->index();
            $table->unsignedBigInteger('booking_id')->nullable()->index();
            $table->enum('decision_type', [
                'tier_access', 'early_access', 'discount_eligibility',
                'points_redemption', 'special_offer', 'upgrade_eligibility',
            ])->index();
            $table->enum('decision_result', ['allowed', 'blocked', 'conditional', 'pending'])->index();
            $table->text('decision_reason'); // Human-readable explanation
            $table->string('rule_version_applied', 20)->index(); // Which rule version was used
            $table->json('decision_data'); // Input data used for decision
            $table->json('rule_evaluation_log'); // Step-by-step rule evaluation
            $table->decimal('confidence_score', 5, 4)->nullable(); // 0.0000 to 1.0000
            $table->string('decision_context', 100)->nullable(); // web, mobile, api, staff
            $table->string('session_id', 255)->nullable()->index();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->boolean('is_appealed')->default(false)->index();
            $table->timestamp('decided_at')->index();
            $table->string('decided_by_system', 100)->default('loyalty_engine');
            $table->timestamps();

            // Composite indexes for common queries
            $table->index(['customer_id', 'decision_type', 'decided_at']);
            $table->index(['event_id', 'decision_type', 'decision_result']);
            $table->index(['booking_id', 'decision_type']);
            $table->index(['rule_version_applied', 'decided_at']);
            $table->index(['decision_result', 'is_appealed']);

            // Foreign key constraints
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            $table->foreign('event_id')->references('id')->on('events')->onDelete('set null');
            $table->foreign('booking_id')->references('id')->on('bookings')->onDelete('set null');
        });

        // 3. Loyalty Ledger Table
        Schema::create('loyalty_ledger', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->index();
            $table->enum('transaction_type', [
                'earn', 'redeem', 'expire', 'adjustment', 'bonus',
                'penalty', 'transfer_in', 'transfer_out', 'refund',
            ])->index();
            $table->integer('points_delta'); // Can be positive or negative
            $table->integer('points_balance_before');
            $table->integer('points_balance_after')->index();
            $table->string('reference_type', 100)->index(); // booking, event, manual, system
            $table->unsignedBigInteger('reference_id')->nullable()->index();
            $table->string('idempotency_key', 255)->unique();
            $table->text('transaction_reason'); // Human-readable reason
            $table->json('transaction_metadata'); // Additional context data
            $table->decimal('exchange_rate', 8, 4)->nullable(); // For currency conversions
            $table->string('currency_code', 3)->nullable(); // ISO currency code
            $table->timestamp('transaction_timestamp')->index();
            $table->timestamp('effective_date')->index(); // When points become available/expire
            $table->timestamp('expiry_date')->nullable()->index(); // When earned points expire
            $table->string('processed_by_system', 100)->default('loyalty_ledger');
            $table->string('authorized_by', 100)->nullable(); // For manual adjustments
            $table->boolean('is_reversed')->default(false)->index();
            $table->unsignedBigInteger('reversal_transaction_id')->nullable();
            $table->timestamps();

            // Composite indexes for performance
            $table->index(['customer_id', 'transaction_timestamp']);
            $table->index(['customer_id', 'transaction_type', 'effective_date']);
            $table->index(['reference_type', 'reference_id']);
            $table->index(['transaction_type', 'is_reversed']);
            $table->index(['effective_date', 'expiry_date']);

            // Foreign key constraints
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            $table->foreign('reversal_transaction_id')->references('id')->on('loyalty_ledger')->onDelete('set null');
        });

        // 4. Eligibility Appeals Table
        Schema::create('eligibility_appeals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('eligibility_decision_id')->index();
            $table->unsignedBigInteger('customer_id')->index();
            $table->enum('appeal_status', [
                'submitted', 'under_review', 'approved', 'rejected',
                'escalated', 'withdrawn', 'expired',
            ])->index();
            $table->text('appeal_reason'); // Customer's reason for appeal
            $table->json('supporting_evidence')->nullable(); // Documents, screenshots, etc.
            $table->text('customer_statement')->nullable();
            $table->timestamp('submitted_at')->index();
            $table->string('submitted_via', 50)->default('web'); // web, mobile, phone, email
            $table->unsignedBigInteger('reviewed_by_staff_id')->nullable();
            $table->text('review_notes')->nullable();
            $table->text('resolution_reason')->nullable();
            $table->json('resolution_data')->nullable(); // What was changed/granted
            $table->timestamp('reviewed_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->integer('review_duration_minutes')->nullable(); // For SLA tracking
            $table->enum('resolution_type', [
                'decision_overturned', 'partial_grant', 'no_change',
                'rule_clarification', 'system_error_fixed',
            ])->nullable();
            $table->boolean('customer_notified')->default(false);
            $table->timestamp('customer_notified_at')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index(['appeal_status', 'submitted_at']);
            $table->index(['customer_id', 'appeal_status']);
            $table->index(['reviewed_by_staff_id', 'reviewed_at']);
            $table->index(['resolution_type', 'resolved_at']);

            // Foreign key constraints
            $table->foreign('eligibility_decision_id')->references('id')->on('eligibility_decisions')->onDelete('cascade');
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
        });

        // 5. Loyalty Reconciliation Log Table
        Schema::create('loyalty_reconciliation_log', function (Blueprint $table) {
            $table->id();
            $table->enum('reconciliation_type', [
                'daily_balance', 'monthly_summary', 'annual_audit',
                'customer_dispute', 'system_migration', 'manual_audit',
            ])->index();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->date('reconciliation_date')->index();
            $table->integer('expected_balance');
            $table->integer('actual_balance');
            $table->integer('variance')->index(); // Difference between expected and actual
            $table->enum('variance_status', [
                'no_variance', 'minor_variance', 'significant_variance',
                'critical_variance', 'resolved', 'under_investigation',
            ])->index();
            $table->text('variance_explanation')->nullable();
            $table->json('reconciliation_details'); // Detailed breakdown
            $table->json('corrective_actions')->nullable(); // What was done to fix
            $table->timestamp('reconciliation_started_at')->index();
            $table->timestamp('reconciliation_completed_at')->nullable()->index();
            $table->integer('reconciliation_duration_seconds')->nullable();
            $table->string('reconciled_by_system', 100)->default('loyalty_reconciliation');
            $table->string('reviewed_by_staff', 100)->nullable();
            $table->boolean('requires_manual_review')->default(false)->index();
            $table->boolean('is_resolved')->default(false)->index();
            $table->timestamps();

            // Composite indexes
            $table->index(['reconciliation_type', 'reconciliation_date']);
            $table->index(['customer_id', 'reconciliation_date']);
            $table->index(['variance_status', 'requires_manual_review']);
            $table->index(['is_resolved', 'reconciliation_completed_at']);

            // Foreign key constraints
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
        });

        // 6. Loyalty Points Expiry Schedule Table
        Schema::create('loyalty_points_expiry_schedule', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('earning_transaction_id')->index();
            $table->integer('points_earned');
            $table->integer('points_remaining')->index();
            $table->integer('points_used')->default(0);
            $table->date('earned_date')->index();
            $table->date('expiry_date')->index();
            $table->enum('expiry_status', [
                'active', 'partially_used', 'fully_used', 'expired', 'extended',
            ])->index();
            $table->date('original_expiry_date')->nullable(); // If extended
            $table->text('expiry_reason')->nullable();
            $table->boolean('expiry_notification_sent')->default(false);
            $table->timestamp('expiry_notification_sent_at')->nullable();
            $table->json('usage_history')->nullable(); // Track how points were used
            $table->timestamps();

            // Composite indexes
            $table->index(['customer_id', 'expiry_date']);
            $table->index(['expiry_date', 'expiry_status']);
            $table->index(['earning_transaction_id', 'expiry_status']);
            $table->index(['points_remaining', 'expiry_date']);

            // Foreign key constraints
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            $table->foreign('earning_transaction_id')->references('id')->on('loyalty_ledger')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_points_expiry_schedule');
        Schema::dropIfExists('loyalty_reconciliation_log');
        Schema::dropIfExists('eligibility_appeals');
        Schema::dropIfExists('loyalty_ledger');
        Schema::dropIfExists('eligibility_decisions');
        Schema::dropIfExists('loyalty_rule_versions');
    }
}
