<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Create Analytics Governance Tables - Task 5.1
 * 
 * Creates comprehensive analytics governance infrastructure with KPI definitions,
 * data health checks, metadata tracking, and lineage management.
 * 
 * Requirements: 21.1, 21.2, 21.3
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // KPI Definitions table - versioned calculation logic
        if (!Schema::hasTable('kpi_definitions')) {
            Schema::create('kpi_definitions', function (Blueprint $table) {
                $table->id();
                $table->string('kpi_name', 100)->unique();
                $table->text('kpi_description');
                $table->json('calculation_logic'); // SQL queries, formulas, dependencies
                $table->json('data_sources'); // Tables, views, external sources
                $table->enum('refresh_frequency', ['real_time', 'hourly', 'daily', 'weekly']);
                $table->integer('version')->default(1);
                $table->boolean('is_active')->default(true);
                $table->string('owner_team', 100)->nullable();
                $table->string('business_domain', 100)->nullable();
                $table->json('validation_rules')->nullable(); // Expected ranges, constraints
                $table->json('metadata')->nullable(); // Additional configuration
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
                $table->timestamp('deprecated_at')->nullable();

                $table->index(['is_active', 'kpi_name']);
                $table->index(['refresh_frequency']);
                $table->index(['business_domain']);
                $table->index(['created_at']);
            });
        }

        // Data Health Checks table - automated validation
        if (!Schema::hasTable('data_health_checks')) {
            Schema::create('data_health_checks', function (Blueprint $table) {
                $table->id();
                $table->string('check_name', 100);
                $table->enum('check_type', ['reconciliation', 'consistency', 'completeness', 'accuracy', 'freshness', 'uniqueness']);
                $table->string('target_table', 100);
                $table->string('target_column', 100)->nullable();
                $table->text('check_query'); // SQL query to perform the check
                $table->json('expected_result')->nullable(); // Expected values or ranges
                $table->json('actual_result')->nullable(); // Last check results
                $table->enum('status', ['passed', 'failed', 'warning', 'skipped'])->default('skipped');
                $table->decimal('threshold_warning', 10, 4)->nullable(); // Warning threshold
                $table->decimal('threshold_critical', 10, 4)->nullable(); // Critical threshold
                $table->text('failure_reason')->nullable();
                $table->json('check_metadata')->nullable(); // Additional check configuration
                $table->timestamp('last_checked_at')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

                $table->index(['status', 'last_checked_at']);
                $table->index(['target_table', 'check_type']);
                $table->index(['check_type', 'status']);
                $table->index(['last_checked_at']);
            });
        }

        // Analytics Metadata table - lineage and dependency tracking
        if (!Schema::hasTable('analytics_metadata')) {
            Schema::create('analytics_metadata', function (Blueprint $table) {
                $table->id();
                $table->string('entity_type', 100); // kpi, report, dashboard, dataset
                $table->string('entity_name', 200);
                $table->string('entity_id', 100); // Reference to actual entity
                $table->json('dependencies'); // Upstream dependencies (tables, KPIs, etc.)
                $table->json('dependents')->nullable(); // Downstream consumers
                $table->json('data_lineage'); // Complete lineage chain
                $table->json('transformation_logic')->nullable(); // How data is transformed
                $table->string('owner', 100)->nullable();
                $table->string('steward', 100)->nullable(); // Data steward responsible
                $table->enum('criticality', ['low', 'medium', 'high', 'critical'])->default('medium');
                $table->json('tags')->nullable(); // Searchable tags
                $table->text('documentation')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

                $table->unique(['entity_type', 'entity_name']);
                $table->index(['entity_type', 'entity_id']);
                $table->index(['owner']);
                $table->index(['criticality']);
                $table->index(['created_at']);
            });
        }

        // KPI Calculations History table - track calculation results
        if (!Schema::hasTable('kpi_calculations')) {
            Schema::create('kpi_calculations', function (Blueprint $table) {
                $table->id();
                $table->string('kpi_name', 100);
                $table->integer('kpi_version');
                $table->json('calculation_result'); // The calculated value(s)
                $table->json('calculation_metadata'); // Execution details, row counts, etc.
                $table->timestamp('calculation_period_start');
                $table->timestamp('calculation_period_end');
                $table->timestamp('calculated_at')->useCurrent();
                $table->decimal('execution_time_ms', 10, 2)->nullable();
                $table->enum('status', ['success', 'failed', 'partial'])->default('success');
                $table->text('error_message')->nullable();
                $table->json('data_sources_used')->nullable(); // Which sources were actually used

                $table->foreign('kpi_name')->references('kpi_name')->on('kpi_definitions');
                $table->index(['kpi_name', 'calculated_at']);
                $table->index(['calculation_period_start', 'calculation_period_end']);
                $table->index(['status', 'calculated_at']);
                $table->index(['calculated_at']);
            });
        }

        // Data Quality Alerts table - track and manage data quality issues
        if (!Schema::hasTable('data_quality_alerts')) {
            Schema::create('data_quality_alerts', function (Blueprint $table) {
                $table->id();
                $table->bigInteger('health_check_id');
                $table->enum('alert_level', ['info', 'warning', 'critical']);
                $table->string('alert_title', 200);
                $table->text('alert_description');
                $table->json('alert_data'); // Specific data about the issue
                $table->enum('status', ['open', 'acknowledged', 'resolved', 'suppressed'])->default('open');
                $table->string('assigned_to', 100)->nullable();
                $table->text('resolution_notes')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('acknowledged_at')->nullable();
                $table->timestamp('resolved_at')->nullable();

                $table->foreign('health_check_id')->references('id')->on('data_health_checks');
                $table->index(['status', 'alert_level']);
                $table->index(['assigned_to', 'status']);
                $table->index(['created_at']);
            });
        }

        // Analytics Reconciliation Log table - track reconciliation activities
        if (!Schema::hasTable('analytics_reconciliation_log')) {
            Schema::create('analytics_reconciliation_log', function (Blueprint $table) {
                $table->id();
                $table->string('reconciliation_type', 100); // kpi_vs_source, cross_kpi, etc.
                $table->string('source_entity', 200);
                $table->string('target_entity', 200);
                $table->json('reconciliation_rules'); // Rules used for reconciliation
                $table->json('reconciliation_result'); // Results of reconciliation
                $table->decimal('variance_percentage', 10, 4)->nullable();
                $table->decimal('variance_absolute', 15, 4)->nullable();
                $table->enum('status', ['match', 'variance_acceptable', 'variance_critical', 'failed']);
                $table->text('variance_explanation')->nullable();
                $table->json('remediation_actions')->nullable(); // Suggested or taken actions
                $table->timestamp('reconciled_at')->useCurrent();
                $table->string('reconciled_by', 100)->nullable();

                $table->index(['reconciliation_type', 'reconciled_at']);
                $table->index(['status', 'reconciled_at']);
                $table->index(['source_entity', 'target_entity']);
                $table->index(['reconciled_at']);
            });
        }

        // Analytics Cache Management table - track cached analytics
        if (!Schema::hasTable('analytics_cache_management')) {
            Schema::create('analytics_cache_management', function (Blueprint $table) {
                $table->id();
                $table->string('cache_key', 255)->unique();
                $table->string('entity_type', 100); // kpi, report, dashboard
                $table->string('entity_name', 200);
                $table->json('cache_parameters'); // Parameters used to generate cache
                $table->longText('cached_data'); // The actual cached data (JSON)
                $table->timestamp('cached_at')->useCurrent();
                $table->timestamp('expires_at');
                $table->timestamp('last_accessed_at')->nullable();
                $table->integer('access_count')->default(0);
                $table->enum('status', ['valid', 'stale', 'invalidated'])->default('valid');
                $table->json('invalidation_triggers')->nullable(); // What would invalidate this cache

                $table->index(['entity_type', 'entity_name']);
                $table->index(['expires_at', 'status']);
                $table->index(['last_accessed_at']);
                $table->index(['cached_at']);
            });
        }

        // Analytics Performance Metrics table - track performance
        if (!Schema::hasTable('analytics_performance_metrics')) {
            Schema::create('analytics_performance_metrics', function (Blueprint $table) {
                $table->id();
                $table->string('operation_type', 100); // calculation, reconciliation, health_check
                $table->string('entity_name', 200);
                $table->decimal('execution_time_ms', 10, 2);
                $table->integer('rows_processed')->nullable();
                $table->integer('memory_usage_mb')->nullable();
                $table->json('performance_metadata')->nullable(); // Additional metrics
                $table->timestamp('measured_at')->useCurrent();

                $table->index(['operation_type', 'measured_at']);
                $table->index(['entity_name', 'measured_at']);
                $table->index(['execution_time_ms']);
                $table->index(['measured_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_performance_metrics');
        Schema::dropIfExists('analytics_cache_management');
        Schema::dropIfExists('analytics_reconciliation_log');
        Schema::dropIfExists('data_quality_alerts');
        Schema::dropIfExists('kpi_calculations');
        Schema::dropIfExists('analytics_metadata');
        Schema::dropIfExists('data_health_checks');
        Schema::dropIfExists('kpi_definitions');
    }
};