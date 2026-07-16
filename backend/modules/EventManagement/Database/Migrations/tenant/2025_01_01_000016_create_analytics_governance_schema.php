<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // KPI definitions table with versioned calculation logic
        Schema::create('kpi_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('kpi_name', 100)->unique();
            $table->text('kpi_description');
            $table->json('calculation_logic');
            $table->json('data_sources');
            $table->enum('refresh_frequency', ['real_time', 'hourly', 'daily', 'weekly']);
            $table->integer('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'kpi_name'], 'idx_active_name');
        });

        // Data health checks table for automated validation
        Schema::create('data_health_checks', function (Blueprint $table) {
            $table->id();
            $table->string('check_name', 100);
            $table->enum('check_type', ['reconciliation', 'consistency', 'completeness', 'accuracy']);
            $table->string('target_table', 100);
            $table->text('check_query');
            $table->json('expected_result')->nullable();
            $table->json('actual_result')->nullable();
            $table->enum('status', ['passed', 'failed', 'warning']);
            $table->timestamp('checked_at')->useCurrent();

            $table->index(['status', 'checked_at'], 'idx_status_checked');
            $table->index('target_table', 'idx_target_table');
        });

        // Analytics metadata and lineage tracking
        Schema::create('analytics_lineage', function (Blueprint $table) {
            $table->id();
            $table->string('kpi_name', 100);
            $table->string('source_table', 100);
            $table->string('source_column', 100)->nullable();
            $table->json('transformation_logic')->nullable();
            $table->timestamp('last_updated')->useCurrent();

            $table->foreign('kpi_name')->references('kpi_name')->on('kpi_definitions')->onDelete('cascade');
            $table->index(['kpi_name', 'source_table'], 'idx_kpi_source');
        });

        // Real-time analytics cache for immediate consistency
        Schema::create('analytics_cache', function (Blueprint $table) {
            $table->id();
            $table->string('cache_key', 255)->unique();
            $table->string('kpi_name', 100);
            $table->json('cache_value');
            $table->timestamp('calculated_at')->useCurrent();
            $table->timestamp('expires_at');
            $table->boolean('is_dirty')->default(false);

            $table->foreign('kpi_name')->references('kpi_name')->on('kpi_definitions')->onDelete('cascade');
            $table->index(['expires_at', 'is_dirty'], 'idx_expires_dirty');
            $table->index('calculated_at', 'idx_calculated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_cache');
        Schema::dropIfExists('analytics_lineage');
        Schema::dropIfExists('data_health_checks');
        Schema::dropIfExists('kpi_definitions');
    }
};
