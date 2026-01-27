<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create eligibility_decisions table for access decision logging
        if (!Schema::hasTable('eligibility_decisions')) {
            Schema::create('eligibility_decisions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_id');
                $table->unsignedBigInteger('event_id');
                $table->enum('decision_type', [
                    'tier_access',
                    'early_access',
                    'discount_eligibility',
                    'points_redemption'
                ]);
                $table->enum('decision_result', ['allowed', 'blocked', 'conditional']);
                $table->text('decision_reason');
                $table->string('rule_version_applied', 100);
                $table->json('decision_data'); // Contains rule inputs, calculations, and context
                $table->timestamp('decided_at')->useCurrent();
                $table->timestamps();

                // Indexes for efficient querying
                $table->index(['customer_id', 'event_id'], 'idx_customer_event');
                $table->index(['decision_type', 'decision_result'], 'idx_decision_type_result');
                $table->index('decided_at', 'idx_decided_at');
                $table->index(['customer_id', 'decided_at'], 'idx_customer_timeline');

                // Foreign key constraints
                $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
                $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            });
        }

        // Create loyalty_ledger table for points transaction integrity
        if (!Schema::hasTable('loyalty_ledger')) {
            Schema::create('loyalty_ledger', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_id');
                $table->enum('transaction_type', ['earn', 'redeem', 'expire', 'adjustment']);
                $table->integer('points_delta'); // Can be positive or negative
                $table->integer('points_balance_after');
                $table->string('reference_type', 100); // 'booking', 'event', 'manual_adjustment', etc.
                $table->unsignedBigInteger('reference_id');
                $table->string('idempotency_key', 255)->unique();
                $table->text('transaction_reason')->nullable();
                $table->json('transaction_metadata')->nullable(); // Additional context data
                $table->timestamp('transaction_timestamp')->useCurrent();
                $table->timestamps();

                // Indexes for efficient querying and reconciliation
                $table->index(['customer_id', 'transaction_timestamp'], 'idx_customer_timeline');
                $table->index('idempotency_key', 'idx_idempotency_key');
                $table->index(['reference_type', 'reference_id'], 'idx_reference');
                $table->index('transaction_type', 'idx_transaction_type');

                // Foreign key constraints
                $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            });
        }

        // Create rule_versions table for rule versioning and decision reasoning
        if (!Schema::hasTable('loyalty_rule_versions')) {
            Schema::create('loyalty_rule_versions', function (Blueprint $table) {
                $table->id();
                $table->string('rule_name', 100);
                $table->string('rule_version', 50);
                $table->text('rule_description');
                $table->json('rule_logic'); // Contains the actual rule implementation details
                $table->json('rule_parameters'); // Configurable parameters for the rule
                $table->boolean('is_active')->default(true);
                $table->timestamp('effective_from')->useCurrent();
                $table->timestamp('effective_until')->nullable();
                $table->timestamps();

                // Indexes
                $table->index(['rule_name', 'rule_version'], 'idx_rule_name_version');
                $table->index(['is_active', 'effective_from'], 'idx_active_effective');
                $table->unique(['rule_name', 'rule_version'], 'unique_rule_version');
            });
        }

        // Create decision_appeals table for appeal and override workflows
        if (!Schema::hasTable('eligibility_decision_appeals')) {
            Schema::create('eligibility_decision_appeals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('original_decision_id');
                $table->unsignedBigInteger('customer_id');
                $table->enum('appeal_type', ['customer_appeal', 'staff_override', 'system_correction']);
                $table->text('appeal_reason');
                $table->enum('appeal_status', ['pending', 'approved', 'rejected', 'escalated']);
                $table->unsignedBigInteger('reviewed_by_staff_id')->nullable();
                $table->text('review_notes')->nullable();
                $table->json('appeal_data')->nullable(); // Supporting evidence or context
                $table->timestamp('appealed_at')->useCurrent();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();

                // Indexes
                $table->index('original_decision_id', 'idx_original_decision');
                $table->index(['customer_id', 'appeal_status'], 'idx_customer_status');
                $table->index(['appeal_status', 'appealed_at'], 'idx_status_date');

                // Foreign key constraints
                $table->foreign('original_decision_id')->references('id')->on('eligibility_decisions')->onDelete('cascade');
                $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            });
        }

        // Create points_balance_snapshots table for reconciliation and audit
        if (!Schema::hasTable('loyalty_balance_snapshots')) {
            Schema::create('loyalty_balance_snapshots', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_id');
                $table->integer('points_balance');
                $table->integer('pending_points')->default(0);
                $table->integer('expired_points_ytd')->default(0);
                $table->string('snapshot_type', 50); // 'daily', 'transaction', 'reconciliation'
                $table->json('balance_breakdown')->nullable(); // Breakdown by earning source
                $table->timestamp('snapshot_timestamp')->useCurrent();
                $table->timestamps();

                // Indexes
                $table->index(['customer_id', 'snapshot_timestamp'], 'idx_customer_snapshot');
                $table->index(['snapshot_type', 'snapshot_timestamp'], 'idx_type_timestamp');

                // Foreign key constraints
                $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_balance_snapshots');
        Schema::dropIfExists('eligibility_decision_appeals');
        Schema::dropIfExists('loyalty_rule_versions');
        Schema::dropIfExists('loyalty_ledger');
        Schema::dropIfExists('eligibility_decisions');
    }
};