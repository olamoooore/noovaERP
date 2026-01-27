<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for multi-tenant observability infrastructure.
     * Creates comprehensive monitoring and observability tables for tenant-aware metrics.
     */
    public function up(): void
    {
        // Tenant health metrics for comprehensive tenant monitoring
        Schema::create('tenant_health_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('metric_name')->index(); // e.g., 'booking_rate', 'error_rate', 'response_time'
            $table->string('metric_type')->index(); // 'counter', 'gauge', 'histogram', 'timer'
            $table->decimal('metric_value', 15, 4);
            $table->json('metric_labels')->nullable(); // Additional metric dimensions
            $table->json('metric_metadata')->nullable(); // Context and additional data
            $table->timestamp('metric_timestamp')->index();
            $table->string('collection_source')->index(); // 'api', 'job', 'event', 'manual'
            $table->timestamps();

            // Indexes for efficient querying
            $table->index(['tenant_id', 'metric_name', 'metric_timestamp']);
            $table->index(['metric_name', 'metric_timestamp']);
            $table->index(['tenant_id', 'metric_timestamp']);
        });

        // API performance logging for detailed API monitoring
        Schema::create('api_performance_log', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('request_id')->unique()->index();
            $table->string('endpoint')->index(); // API endpoint path
            $table->string('method', 10)->index(); // HTTP method
            $table->integer('status_code')->index();
            $table->integer('response_time_ms'); // Response time in milliseconds
            $table->integer('memory_usage_mb')->nullable(); // Memory usage in MB
            $table->integer('db_query_count')->nullable(); // Number of database queries
            $table->integer('db_query_time_ms')->nullable(); // Total DB query time
            $table->json('request_headers')->nullable();
            $table->json('response_headers')->nullable();
            $table->text('error_message')->nullable();
            $table->json('performance_metrics')->nullable(); // Additional performance data
            $table->string('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('user_agent')->nullable();
            $table->timestamp('request_timestamp')->index();
            $table->timestamps();

            // Indexes for performance analysis
            $table->index(['tenant_id', 'endpoint', 'request_timestamp']);
            $table->index(['endpoint', 'status_code', 'request_timestamp']);
            $table->index(['tenant_id', 'status_code', 'request_timestamp']);
            $table->index(['response_time_ms', 'request_timestamp']);
        });

        // Queue health monitoring for job and queue performance
        Schema::create('queue_health_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('queue_name')->index(); // Queue identifier
            $table->string('job_class')->index(); // Job class name
            $table->string('job_id')->nullable()->index(); // Specific job ID
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'retrying'])->index();
            $table->integer('queue_size')->nullable(); // Current queue size
            $table->integer('processing_time_ms')->nullable(); // Job processing time
            $table->integer('wait_time_ms')->nullable(); // Time in queue before processing
            $table->integer('attempt_number')->default(1); // Retry attempt number
            $table->text('error_message')->nullable();
            $table->json('job_payload')->nullable(); // Job data for analysis
            $table->json('performance_metrics')->nullable(); // Additional metrics
            $table->timestamp('queued_at')->nullable()->index();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();

            // Indexes for queue monitoring
            $table->index(['tenant_id', 'queue_name', 'status']);
            $table->index(['queue_name', 'job_class', 'status']);
            $table->index(['tenant_id', 'queued_at']);
            $table->index(['processing_time_ms', 'completed_at']);
        });

        // Tenant resource usage tracking
        Schema::create('tenant_resource_usage', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('resource_type')->index(); // 'cpu', 'memory', 'storage', 'api_calls', 'db_connections'
            $table->decimal('usage_value', 15, 4); // Resource usage amount
            $table->string('usage_unit', 20); // 'percent', 'mb', 'gb', 'count', 'seconds'
            $table->decimal('limit_value', 15, 4)->nullable(); // Resource limit if applicable
            $table->decimal('usage_percentage', 5, 2)->nullable(); // Percentage of limit used
            $table->json('usage_breakdown')->nullable(); // Detailed usage breakdown
            $table->timestamp('measurement_timestamp')->index();
            $table->string('measurement_source')->index(); // Source of measurement
            $table->timestamps();

            // Indexes for resource monitoring
            $table->index(['tenant_id', 'resource_type', 'measurement_timestamp']);
            $table->index(['resource_type', 'usage_percentage', 'measurement_timestamp']);
            $table->index(['tenant_id', 'measurement_timestamp']);
        });

        // System health alerts and thresholds
        Schema::create('observability_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->nullable()->index(); // Null for system-wide alerts
            $table->string('alert_name')->index();
            $table->enum('alert_level', ['info', 'warning', 'error', 'critical'])->index();
            $table->enum('alert_status', ['active', 'acknowledged', 'resolved', 'suppressed'])->index();
            $table->text('alert_message');
            $table->json('alert_context')->nullable(); // Additional alert context
            $table->json('threshold_config')->nullable(); // Threshold configuration
            $table->decimal('current_value', 15, 4)->nullable(); // Current metric value
            $table->decimal('threshold_value', 15, 4)->nullable(); // Threshold that was breached
            $table->timestamp('first_triggered_at')->index();
            $table->timestamp('last_triggered_at')->index();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('acknowledged_by')->nullable();
            $table->string('resolved_by')->nullable();
            $table->integer('trigger_count')->default(1); // Number of times triggered
            $table->timestamps();

            // Indexes for alert management
            $table->index(['tenant_id', 'alert_status', 'alert_level']);
            $table->index(['alert_name', 'alert_status', 'last_triggered_at']);
            $table->index(['alert_level', 'alert_status', 'last_triggered_at']);
        });

        // Tenant capacity planning and forecasting
        Schema::create('tenant_capacity_forecasts', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('forecast_type')->index(); // 'booking_volume', 'api_usage', 'storage', 'users'
            $table->date('forecast_date')->index(); // Date being forecasted
            $table->decimal('forecasted_value', 15, 4); // Predicted value
            $table->decimal('confidence_interval', 5, 2)->nullable(); // Confidence percentage
            $table->json('forecast_factors')->nullable(); // Factors influencing forecast
            $table->json('historical_data')->nullable(); // Historical data used
            $table->string('forecast_model')->nullable(); // Model used for forecasting
            $table->decimal('actual_value', 15, 4)->nullable(); // Actual value when available
            $table->decimal('forecast_accuracy', 5, 2)->nullable(); // Accuracy percentage
            $table->timestamp('generated_at')->index();
            $table->timestamps();

            // Indexes for capacity planning
            $table->index(['tenant_id', 'forecast_type', 'forecast_date']);
            $table->index(['forecast_type', 'forecast_date', 'generated_at']);
            $table->index(['tenant_id', 'generated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_capacity_forecasts');
        Schema::dropIfExists('observability_alerts');
        Schema::dropIfExists('tenant_resource_usage');
        Schema::dropIfExists('queue_health_metrics');
        Schema::dropIfExists('api_performance_log');
        Schema::dropIfExists('tenant_health_metrics');
    }
};