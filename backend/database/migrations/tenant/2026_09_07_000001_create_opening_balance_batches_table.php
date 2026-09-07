<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACC-001 Opening Balance — batch/version aggregate.
 *
 * Issue #70 (ACC-DEC-060 Track B): persists the opening balance
 * lifecycle from DRAFT through POSTED. Each batch represents one
 * immutable source version for a controlled scope (tenant + entity +
 * book + cutover date + population scope).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_balance_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Scope
            $table->uuid('tenant_id');
            $table->uuid('legal_entity_id')->nullable();
            $table->uuid('accounting_book_id')->nullable();

            // Lifecycle
            $table->enum('status', [
                'draft',
                'imported',
                'validated',
                'reconciled',
                'pending_approval',
                'approved',
                'posting_authorised',
                'posting',
                'posted',
                'validation_failed',
                'reconciliation_failed',
                'rejected',
                'posting_failed',
                'superseded',
            ])->default('draft');

            // Source provenance
            $table->string('source_type', 50);        // csv_import, manual, migration
            $table->string('source_reference')->nullable();
            $table->string('source_hash', 64);         // SHA-256 of immutable source data
            $table->unsignedInteger('source_version')->default(1);

            // Cutover boundary
            $table->date('cutover_date');
            $table->unsignedBigInteger('fiscal_period_id');

            // Canonical population scope
            $table->string('population_scope', 100);   // full_trial_balance, partial_ar, etc.

            // Mapping/configuration provenance
            $table->string('mapping_version')->nullable();
            $table->string('mapping_hash', 64)->nullable();

            // Approval
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->json('approval_snapshot')->nullable(); // immutable snapshot at approval

            // Posting
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable(); // ACC-002 linkage

            // Audit
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            // Indexes
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'legal_entity_id', 'accounting_book_id', 'cutover_date']);
            $table->index('fiscal_period_id');
            $table->index('source_hash');
            $table->index('journal_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balance_batches');
    }
};
