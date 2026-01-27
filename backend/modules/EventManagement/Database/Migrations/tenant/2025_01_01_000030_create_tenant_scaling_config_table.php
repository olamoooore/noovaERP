<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Create Tenant Scaling Configuration Table - Task 11.4
 * 
 * Creates tenant scaling configuration table for automated scaling
 * triggers, alerts, and resource allocation management.
 * 
 * Requirements: 27.5
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Tenant Scaling Configuration table
        if (!Schema::hasTable('tenant_scaling_config')) {
            Schema::create('tenant_scaling_config', function (Blueprint $table) {
                $table->id();
                $table->string('tenant_id', 100)->unique();
                $table->json('scaling_thresholds'); // CPU, memory, storage, API calls
                $table->json('scaling_actions'); // Scale up/down actions
                $table->json('resource_limits'); // Maximum allowed resources
                $table->json('cost_controls'); // Budget limits and alerts
                $table->boolean('auto_scaling_enabled')->default(false);
                $table->integer('scale_up_cooldown_minutes')->default(10);
                $table->integer('scale_down_cooldown_minutes')->default(30);
                $table->json('notification_settings'); // Who to notify on scaling events
                $table->json('maintenance_windows')->nullable(); // When scaling is allowed
                $table->json('metadata')->nullable();
                $table->timestamps();

                // Indexes for scaling operations
                $table->index(['tenant_id']);
                $table->index(['auto_scaling_enabled']);
                $table->index(['created_at']);
            });
        }

        // Tenant Scaling Events table for tracking scaling history
        if (!Schema::hasTable('tenant_scaling_events')) {
            Schema::create('tenant_scaling_events', function (Blueprint $table) {
                $table->id();
                $table->string('tenant_id', 100);
                $table->enum('event_type', ['scale_up', 'scale_down', 'threshold_breach', 'manual_override']);
                $table->string('resource_type', 100); // compute, storage, bandwidth
                $table->json('before_state'); // Resource state before scaling
                $table->json('after_state'); // Resource state after scaling
                $table->json('trigger_metrics'); // Metrics that triggered the event
                $table->enum('status', ['initiated', 'in_progress', 'completed', 'failed', 'rolled_back']);
                $table->text('reason')->nullable();
                $table->text('error_message')->nullable();
                $table->string('initiated_by', 100)->nullable(); // user_id or 'system'
                $table->timestamp('initiated_at');
                $table->timestamp('completed_at')->nullable();
                $table->decimal('cost_impact', 10, 2)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                // Foreign key constraint
                $table->foreign('tenant_id')->references('tenant_id')->on('tenant_scaling_config')->onDelete('cascade');

                // Indexes for scaling history
                $table->index(['tenant_id', 'initiated_at'], 'tse_tid_ia_index');
                $table->index(['event_type', 'status'], 'tse_et_s_index');
                $table->index(['resource_type', 'initiated_at'], 'tse_rt_ia_index');
                $table->index(['status', 'initiated_at'], 'tse_s_ia_index');
                $table->index(['initiated_at']);
            });
        }

        // Tenant Resource Usage Forecasts table
        if (!Schema::hasTable('tenant_resource_forecasts')) {
            Schema::create('tenant_resource_forecasts', function (Blueprint $table) {
                $table->id();
                $table->string('tenant_id', 100);
                $table->string('resource_type', 100);
                $table->date('forecast_date');
                $table->decimal('predicted_usage', 15, 4);
                $table->decimal('confidence_interval_lower', 15, 4);
                $table->decimal('confidence_interval_upper', 15, 4);
                $table->decimal('forecast_accuracy', 5, 2)->nullable(); // Actual vs predicted
                $table->json('forecast_model_metadata'); // Model used, parameters, etc.
                $table->json('seasonal_factors')->nullable();
                $table->json('trend_factors')->nullable();
                $table->timestamp('generated_at');
                $table->timestamps();

                // Foreign key constraint
                $table->foreign('tenant_id')->references('tenant_id')->on('tenant_scaling_config')->onDelete('cascade');

                // Unique constraint for forecasts - Shortened Name
                $table->unique(['tenant_id', 'resource_type', 'forecast_date'], 'trf_tid_rt_fd_unique');

                // Indexes for forecast queries
                // Removed redundant index covered by unique constraint
                $table->index(['forecast_date']);
                $table->index(['generated_at']);
            });
        }

        // Tenant Cost Optimization Recommendations table
        if (!Schema::hasTable('tenant_cost_optimization_recommendations')) {
            Schema::create('tenant_cost_optimization_recommendations', function (Blueprint $table) {
                $table->id();
                $table->string('tenant_id', 100);
                $table->string('recommendation_type', 100); // rightsizing, scheduling, reserved_capacity
                $table->string('resource_type', 100);
                $table->text('recommendation_description');
                $table->decimal('potential_savings_monthly', 10, 2);
                $table->decimal('implementation_effort', 3, 1); // 1-10 scale
                $table->json('implementation_steps')->nullable();
                $table->enum('priority', ['low', 'medium', 'high', 'critical']);
                $table->enum('status', ['pending', 'approved', 'implemented', 'rejected', 'expired']);
                $table->date('valid_until');
                $table->json('supporting_data'); // Usage patterns, cost analysis
                $table->string('generated_by', 100)->default('system');
                $table->string('reviewed_by', 100)->nullable();
                $table->text('review_notes')->nullable();
                $table->timestamp('generated_at');
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();

                // Foreign key constraint
                $table->foreign('tenant_id')->references('tenant_id')->on('tenant_scaling_config')->onDelete('cascade');

                // Indexes for recommendations - Shortened Names
                $table->index(['tenant_id', 'status'], 'tcor_tid_s_index');
                $table->index(['recommendation_type', 'priority'], 'tcor_rt_p_index');
                $table->index(['status', 'valid_until'], 'tcor_s_vu_index');
                $table->index(['potential_savings_monthly'], 'tcor_psm_index');
                $table->index(['generated_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_cost_optimization_recommendations');
        Schema::dropIfExists('tenant_resource_forecasts');
        Schema::dropIfExists('tenant_scaling_events');
        Schema::dropIfExists('tenant_scaling_config');
    }
};