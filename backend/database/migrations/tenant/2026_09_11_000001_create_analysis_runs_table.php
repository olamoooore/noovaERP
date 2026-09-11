<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2-IMPL-01A — AnalysisRun, SourceStateBinding, Staleness foundation.
 *
 * Issue #72: persisted analysis-run identity with mandatory scope
 * (tenant/entity/book/period), source-state binding, governed
 * current-run selection, and staleness evaluation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('run_uuid')->unique();
            $table->string('analysis_type', 100)->comment('e.g. financial_close_ai, assurance, reconciliation');
            $table->uuid('tenant_id');
            $table->uuid('legal_entity_id');
            $table->uuid('accounting_book_id');
            $table->foreignId('fiscal_period_id')->constrained('fiscal_periods');
            $table->string('status', 50)->default('admitted')->comment('admitted | superseded | stale');
            $table->boolean('is_current')->default(true)->comment('governed current-run flag');
            $table->text('scope_hash')->comment('SHA-256 of tenant+entity+book+period+analysis_type');
            $table->jsonb('source_state')->nullable()->comment('SourceStateBinding snapshot at admission');
            $table->string('source_state_hash', 64)->nullable()->comment('SHA-256 of source_state for drift detection');
            $table->timestamp('admitted_at');
            $table->timestamp('stale_at')->nullable();
            $table->string('stale_reason', 255)->nullable();
            $table->uuid('superseded_by_run_uuid')->nullable();
            $table->string('correlation_id', 100)->nullable()->comment('caller-supplied correlation/request identity');
            $table->text('description')->nullable();
            $table->timestamps();

            // EVID-02A-003: scope = tenant/entity/book/period — composite unique
            // prevents duplicate current runs for the same scope + analysis type.
            $table->unique(['analysis_type', 'tenant_id', 'legal_entity_id', 'accounting_book_id', 'fiscal_period_id', 'is_current'], 'analysis_run_scope_current_idx');
            $table->index(['tenant_id', 'analysis_type', 'status']);
            $table->index(['is_current', 'analysis_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_runs');
    }
};
