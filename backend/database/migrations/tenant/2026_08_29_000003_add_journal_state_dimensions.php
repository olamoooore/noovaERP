<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ACC-RM-011: Journal business, workflow, approval and posting states are
     * distinct state dimensions per ADR-005 §11.5.
     *
     * Canonical values:
     * - journal_business_state: DRAFT, SUBMITTED, CANCELLED
     * - workflow_state:        (workflow-specific; nullable if no workflow)
     * - approval_state:        NOT_REQUIRED, PENDING, APPROVED, REJECTED
     * - posting_state:         NOT_POSTED, POSTING, POSTED, POSTING_FAILED, REVERSED
     *
     * The legacy `status` and `lifecycle_status` columns are kept for backward
     * compatibility but should be treated as a derived summary of the four
     * new dimensions (see ADR-005 §11.5.4).
     */
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            // Business state — controls whether the journal is in preparation,
            // has been submitted for posting, or has been cancelled at the
            // business layer (distinct from posting-state reversal).
            $table->string('journal_business_state', 32)
                ->nullable()
                ->after('status')
                ->comment('DRAFT | SUBMITTED | CANCELLED (ADR-005 §11.5.1)');

            // Workflow state — optional, populated only if a workflow applies.
            // Free-form string to accommodate varied workflow engines.
            $table->string('workflow_state', 64)
                ->nullable()
                ->after('journal_business_state')
                ->comment('Workflow-specific state (ADR-005 §11.5.2)');

            // Approval state — separate dimension from posting.
            $table->string('approval_state', 32)
                ->nullable()
                ->after('workflow_state')
                ->comment('NOT_REQUIRED | PENDING | APPROVED | REJECTED (ADR-005 §11.5.3)');

            // Posting state — tracks the ledger-posting lifecycle.
            $table->string('posting_state', 32)
                ->nullable()
                ->after('approval_state')
                ->comment('NOT_POSTED | POSTING | POSTED | POSTING_FAILED | REVERSED (ADR-005 §11.5.4)');

            // Index for the most common filter combinations
            $table->index(['posting_state', 'journal_business_state'], 'je_posting_business_state_idx');
            $table->index(['approval_state', 'posting_state'], 'je_approval_posting_idx');
        });

        // Backfill canonical defaults for existing rows. New rows will get
        // these set by the model's `creating` hook (see JournalEntry::boot()).
        DB::table('journal_entries')
            ->whereNull('journal_business_state')
            ->update(['journal_business_state' => DB::raw("CASE WHEN status = 'draft' THEN 'DRAFT' WHEN status = 'cancelled' THEN 'CANCELLED' ELSE 'SUBMITTED' END")]);
        DB::table('journal_entries')->whereNull('workflow_state')->update(['workflow_state' => 'NONE']);
        DB::table('journal_entries')->whereNull('approval_state')->update(['approval_state' => 'NOT_REQUIRED']);
        DB::table('journal_entries')
            ->whereNull('posting_state')
            ->update(['posting_state' => DB::raw("CASE WHEN status = 'posted' THEN 'POSTED' WHEN status = 'reversed' THEN 'REVERSED' WHEN status = 'posting_failed' THEN 'POSTING_FAILED' WHEN status = 'posting' THEN 'POSTING' ELSE 'NOT_POSTED' END")]);
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex('je_posting_business_state_idx');
            $table->dropIndex('je_approval_posting_idx');
            $table->dropColumn(['journal_business_state', 'workflow_state', 'approval_state', 'posting_state']);
        });
    }
};
