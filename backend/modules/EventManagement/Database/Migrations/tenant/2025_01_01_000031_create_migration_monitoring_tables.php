<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Create Migration Monitoring Tables
 *
 * Creates tables for migration monitoring, alerting, and conflict detection
 * to ensure production safety and operational visibility.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Migration alerts table for storing alert history
        Schema::create('migration_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50); // pre_validation_failed, execution_failed, etc.
            $table->string('migration_name', 255);
            $table->enum('severity', ['low', 'medium', 'high', 'critical']);
            $table->json('context'); // Additional alert context
            $table->timestamp('created_at');
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolved_by', 100)->nullable();
            $table->text('resolution_notes')->nullable();

            $table->index(['type', 'severity']);
            $table->index(['migration_name']);
            $table->index(['created_at']);
            $table->index(['resolved_at']);
        });

        // Migration execution log for detailed tracking
        Schema::create('migration_execution_log', function (Blueprint $table) {
            $table->id();
            $table->string('monitoring_id', 100)->unique();
            $table->string('migration_name', 255);
            $table->enum('status', ['running', 'completed', 'failed', 'rolled_back']);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->decimal('execution_time_seconds', 10, 3)->nullable();
            $table->bigInteger('memory_usage_bytes')->nullable();
            $table->json('pre_validation_results')->nullable();
            $table->json('post_validation_results')->nullable();
            $table->text('error_message')->nullable();
            $table->json('performance_metrics')->nullable();
            $table->string('executed_by', 100)->nullable();
            $table->string('environment', 50)->default('production');

            $table->index(['migration_name', 'status']);
            $table->index(['started_at']);
            $table->index(['status']);
            $table->index(['environment']);
        });

        // Migration dependencies tracking
        Schema::create('migration_dependencies', function (Blueprint $table) {
            $table->id();
            $table->string('migration_name', 255);
            $table->string('depends_on', 255);
            $table->enum('dependency_type', ['table', 'foreign_key', 'feature', 'data']);
            $table->boolean('is_critical')->default(true);
            $table->text('description')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->unique(['migration_name', 'depends_on', 'dependency_type']);
            $table->index(['migration_name']);
            $table->index(['depends_on']);
            $table->index(['dependency_type']);
        });

        // Migration conflict detection
        Schema::create('migration_conflicts', function (Blueprint $table) {
            $table->id();
            $table->string('conflict_type', 50); // timestamp_duplicate, dependency_violation, etc.
            $table->json('affected_migrations'); // Array of migration names
            $table->enum('severity', ['warning', 'error', 'critical']);
            $table->text('description');
            $table->json('resolution_suggestions')->nullable();
            $table->enum('status', ['detected', 'acknowledged', 'resolved', 'ignored']);
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolved_by', 100)->nullable();
            $table->text('resolution_notes')->nullable();

            $table->index(['conflict_type', 'status']);
            $table->index(['severity']);
            $table->index(['detected_at']);
            $table->index(['status']);
        });

        // Migration performance baselines
        Schema::create('migration_performance_baselines', function (Blueprint $table) {
            $table->id();
            $table->string('migration_pattern', 255); // Pattern like 'create_*_table'
            $table->decimal('avg_execution_time_seconds', 10, 3);
            $table->bigInteger('avg_memory_usage_bytes');
            $table->decimal('max_execution_time_seconds', 10, 3);
            $table->bigInteger('max_memory_usage_bytes');
            $table->integer('execution_count');
            $table->timestamp('last_updated');
            $table->json('performance_trends')->nullable();

            $table->unique(['migration_pattern']);
            $table->index(['last_updated']);
        });

        // System health snapshots during migrations
        Schema::create('migration_system_health', function (Blueprint $table) {
            $table->id();
            $table->string('monitoring_id', 100);
            $table->timestamp('snapshot_time');
            $table->decimal('cpu_usage_percent', 5, 2)->nullable();
            $table->bigInteger('memory_usage_bytes')->nullable();
            $table->bigInteger('disk_free_bytes')->nullable();
            $table->integer('active_connections')->nullable();
            $table->decimal('query_response_time_ms', 10, 3)->nullable();
            $table->json('additional_metrics')->nullable();

            $table->index(['monitoring_id', 'snapshot_time']);
            $table->index(['snapshot_time']);

            $table->foreign('monitoring_id')
                ->references('monitoring_id')
                ->on('migration_execution_log')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('migration_system_health');
        Schema::dropIfExists('migration_performance_baselines');
        Schema::dropIfExists('migration_conflicts');
        Schema::dropIfExists('migration_dependencies');
        Schema::dropIfExists('migration_execution_log');
        Schema::dropIfExists('migration_alerts');
    }
};
