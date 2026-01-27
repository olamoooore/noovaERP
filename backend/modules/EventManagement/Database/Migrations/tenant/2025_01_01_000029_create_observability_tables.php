<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Create Observability Tables - Task 11.1
 * 
 * Creates observability database schema for tenant health metrics,
 * API performance logging, and queue health monitoring.
 * 
 * Requirements: 27.1, 27.2, 27.3
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Tenant Health Metrics table for tenant-aware monitoring
        if (!Schema::hasTable('tenant_health_metrics')) {
            Schema::create('tenant_health_metrics', function (Blueprint $table) {
                $table->id();
                $table->string('tenant_id', 100);
                $table->string('metric_name', 100);
                $table->enum('metric_type', ['counter', 'gauge', 'histogram', 'summary']);
                $table->decimal('metric_value', 15, 4);
                $table->json('metric_labels')->nullable(); // Additional dimensions
                $table->string('metric_unit', 50)->nullable(); // bytes, seconds, count, etc.
                $table->timestamp('measured_at');
                $table->string('source_component', 100)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                // Indexes for time-series queries
                $table->index(['tenant_id', 'metric_name', 'measured_at']);
                $table->index(['metric_name', 'measured_at']);
                $table->index(['tenant_id', 'measured_at']);
                $table->index(['source_component', 'measured_at']);
                $table->index(['measured_at']);
            });
        }

        // API Performance Log table for API monitoring
        if (!Schema::hasTable('api_performance_log')) {
            Schema::create('api_performance_log', function (Blueprint $table) {
                $table->id();
                $table->string('tenant_id', 100);
                $table->string('endpoint', 255);
                $table->string('method', 10);
                $table->integer('response_status');
                $table->decimal('response_time_ms', 10, 2);
                $table->integer('request_size_bytes')->nullable();
                $table->integer('response_size_bytes')->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('request_id', 100)->nullable();
                $table->json('request_headers')->nullable();
                $table->json('response_headers')->nullable();
                $table->text('error_message')->nullable();
                $table->json('performance_metadata')->nullable();
                $table->timestamp('requested_at');
                $table->timestamps();

                // Indexes for performance analysis
                $table->index(['tenant_id', 'endpoint', 'requested_at']);
                $table->index(['endpoint', 'method', 'requested_at']);
                $table->index(['response_status', 'requested_at']);
                $table->index(['response_time_ms']);
                $table->index(['requested_at']);
                $table->index(['tenant_id', 'requested_at']);
            });
        }

        // Queue Health Metrics table for job performance tracking
        if (!Schema::hasTable('queue_health_metrics')) {
            Schema::create('queue_health_metrics', function (Blueprint $table) {
                $table->id();
                $table->string('tenant_id', 100);
                $table->string('queue_name', 100);
                $table->string('job_class', 255);
                $table->enum('job_status', ['pending', 'processing', 'completed', 'failed', 'retrying']);
                $table->decimal('processing_time_ms', 10, 2)->nullable();
                $table->integer('memory_usage_mb')->nullable();
                $table->integer('retry_count')->default(0);
                $table->text('error_message')->nullable();
                $table->json('job_payload')->nullable();
                $table->timestamp('queued_at');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->json('performance_metadata')->nullable();
                $table->timestamps();

                // Indexes for queue monitoring
                $table->index(['tenant_id', 'queue_name', 'job_status']);
                $table->index(['queue_name', 'job_status', 'queued_at']);
                $table->index(['job_class', 'job_status']);
                $table->index(['processing_time_ms']);
                $table->index(['queued_at']);
                $table->index(['completed_at']);
            });
        }

        // System Resource Metrics table for infrastructure monitoring
        if (!Schema::hasTable('system_resource_metrics')) {
            Schema::create('system_resource_metrics', function (Blueprint $table) {
                $table->id();
                $table->string('server_id', 100);
                $table->string('metric_name', 100); // cpu_usage, memory_usage, disk_usage, etc.
                $table->decimal('metric_value', 10, 4);
                $table->string('metric_unit', 20); // percent, bytes, count
                $table->json('metric_dimensions')->nullable(); // core, mount_point, etc.
                $table->timestamp('measured_at');
                $table->json('metadata')->nullable();
                $table->timestamps();

                // Indexes for infrastructure monitoring
                $table->index(['server_id', 'metric_name', 'measured_at']);
                $table->index(['metric_name', 'measured_at']);
                $table->index(['measured_at']);
            });
        }

        // Alert Rules table for configurable alerting
        if (!Schema::hasTable('alert_rules')) {
            Schema::create('alert_rules', function (Blueprint $table) {
                $table->id();
                $table->string('rule_name', 200)->unique();
                $table->text('rule_description');
                $table->enum('rule_type', ['threshold', 'anomaly', 'trend', 'composite']);
                $table->string('metric_name', 100);
                $table->json('rule_conditions'); // Threshold values, operators, etc.
                $table->enum('severity', ['info', 'warning', 'critical']);
                $table->json('notification_channels'); // email, slack, webhook, etc.
                $table->boolean('is_active')->default(true);
                $table->string('tenant_scope', 100)->nullable(); // null for global rules
                $table->integer('evaluation_interval_seconds')->default(60);
                $table->integer('cooldown_seconds')->default(300);
                $table->json('metadata')->nullable();
                $table->timestamps();

                // Indexes for rule evaluation
                $table->index(['is_active', 'metric_name']);
                $table->index(['tenant_scope', 'is_active']);
                $table->index(['rule_type']);
                $table->index(['severity']);
            });
        }

        // Alert Instances table for tracking fired alerts
        if (!Schema::hasTable('alert_instances')) {
            Schema::create('alert_instances', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('rule_id');
                $table->string('tenant_id', 100)->nullable();
                $table->enum('status', ['firing', 'resolved', 'suppressed']);
                $table->json('alert_data'); // Metric values that triggered the alert
                $table->text('alert_message');
                $table->timestamp('fired_at');
                $table->timestamp('resolved_at')->nullable();
                $table->timestamp('last_notification_at')->nullable();
                $table->integer('notification_count')->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();

                // Foreign key constraint
                $table->foreign('rule_id')->references('id')->on('alert_rules')->onDelete('cascade');

                // Indexes for alert management
                $table->index(['rule_id', 'status']);
                $table->index(['tenant_id', 'status']);
                $table->index(['status', 'fired_at']);
                $table->index(['fired_at']);
                $table->index(['resolved_at']);
            });
        }

        // Performance Baselines table for anomaly detection
        if (!Schema::hasTable('performance_baselines')) {
            Schema::create('performance_baselines', function (Blueprint $table) {
                $table->id();
                $table->string('tenant_id', 100);
                $table->string('metric_name', 100);
                $table->string('time_period', 50); // hourly, daily, weekly
                $table->decimal('baseline_value', 15, 4);
                $table->decimal('standard_deviation', 15, 4)->nullable();
                $table->decimal('percentile_95', 15, 4)->nullable();
                $table->decimal('percentile_99', 15, 4)->nullable();
                $table->integer('sample_count');
                $table->date('baseline_date');
                $table->json('calculation_metadata')->nullable();
                $table->timestamps();

                // Unique constraint for baseline periods
                $table->unique(['tenant_id', 'metric_name', 'time_period', 'baseline_date']);

                // Indexes for baseline queries
                $table->index(['tenant_id', 'metric_name', 'baseline_date']);
                $table->index(['metric_name', 'time_period']);
                $table->index(['baseline_date']);
            });
        }

        // Capacity Planning Metrics table for resource forecasting
        if (!Schema::hasTable('capacity_planning_metrics')) {
            Schema::create('capacity_planning_metrics', function (Blueprint $table) {
                $table->id();
                $table->string('tenant_id', 100);
                $table->string('resource_type', 100); // storage, compute, bandwidth, etc.
                $table->decimal('current_usage', 15, 4);
                $table->decimal('capacity_limit', 15, 4);
                $table->decimal('utilization_percentage', 5, 2);
                $table->decimal('growth_rate_daily', 10, 6)->nullable();
                $table->decimal('projected_exhaustion_days', 10, 2)->nullable();
                $table->json('usage_patterns')->nullable(); // Seasonal, trend data
                $table->timestamp('measured_at');
                $table->json('metadata')->nullable();
                $table->timestamps();

                // Indexes for capacity planning
                $table->index(['tenant_id', 'resource_type', 'measured_at']);
                $table->index(['resource_type', 'utilization_percentage']);
                $table->index(['projected_exhaustion_days']);
                $table->index(['measured_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('capacity_planning_metrics');
        Schema::dropIfExists('performance_baselines');
        Schema::dropIfExists('alert_instances');
        Schema::dropIfExists('alert_rules');
        Schema::dropIfExists('system_resource_metrics');
        Schema::dropIfExists('queue_health_metrics');
        Schema::dropIfExists('api_performance_log');
        Schema::dropIfExists('tenant_health_metrics');
    }
};