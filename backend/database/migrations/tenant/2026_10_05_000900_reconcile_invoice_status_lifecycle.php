<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #900 (PC6) — one authoritative status per invoice, lifecycle derived.
 *
 * Invoices carried `status` and `lifecycle_status` kept in sync by hand; the
 * reconciliation paths could drift (a cancelled invoice whose lifecycle stayed
 * 'posted' kept counting in P&L / receivable reports, and a reset draft whose
 * lifecycle stayed 'posted' kept counting in scopePosted() reports).
 *
 * Decision (see PR): neither column is dropped — both are read widely
 * (`status` by the operational UI/settlement logic, `lifecycle_status` by
 * reports and correction guards; ADR-005 treats the posting lifecycle as its
 * own dimension). Instead:
 *   • `status` stays the single field the workflow engine writes;
 *   • `lifecycle_status` is derived from `status` in Invoice::applyWorkflowStatus();
 *   • this migration repairs the drift accumulated by the old hand-sync.
 *
 * The backfill is additive and only touches rows that are provably drifted:
 * cancellation (status='cancelled') and reset-to-draft (status='draft' with
 * no journal entry). Correction lifecycles (voided/reversed/credited/
 * superseded) are owned by DocumentLifecycleService and left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoices') || ! Schema::hasColumn('invoices', 'lifecycle_status')) {
            return;
        }

        // 1. NULL lifecycle on a row that has an operational status. Separate
        //    statements (not CASE) so the untyped literals coerce to the
        //    Postgres enum column type used by the correction convergence.
        DB::statement("UPDATE invoices SET lifecycle_status = 'cancelled' WHERE lifecycle_status IS NULL AND status = 'cancelled'");
        DB::statement("UPDATE invoices SET lifecycle_status = 'draft' WHERE lifecycle_status IS NULL AND status = 'draft'");
        DB::statement("UPDATE invoices SET lifecycle_status = 'posted' WHERE lifecycle_status IS NULL");

        // 2. A cancelled invoice that still reports as an active lifecycle.
        //    (Correction lifecycles are excluded: voided/reversed/credited/
        //    superseded are legitimate end states that are not 'cancelled'.)
        DB::statement("
            UPDATE invoices
            SET lifecycle_status = 'cancelled'
            WHERE status = 'cancelled'
              AND lifecycle_status IN ('draft', 'posted')
        ");

        // 3. A reset draft whose posting lifecycle was never cleared.
        DB::statement("
            UPDATE invoices
            SET lifecycle_status = 'draft'
            WHERE status = 'draft'
              AND lifecycle_status = 'posted'
              AND journal_entry_id IS NULL
        ");

        // 4. A posted document (journal entry present) whose lifecycle stayed
        //    'draft' — e.g. rows posted by the legacy path that only wrote
        //    `status` before the lifecycle column existed.
        DB::statement("
            UPDATE invoices
            SET lifecycle_status = 'posted'
            WHERE status IN ('posted', 'sent', 'partial', 'paid', 'overdue')
              AND lifecycle_status = 'draft'
              AND journal_entry_id IS NOT NULL
        ");

        // 5. Derive the settlement dimension where it was never populated.
        if (Schema::hasColumn('invoices', 'settlement_status')) {
            DB::statement("UPDATE invoices SET settlement_status = 'unpaid' WHERE settlement_status IS NULL AND paid_amount <= 0");
            DB::statement("UPDATE invoices SET settlement_status = 'paid' WHERE settlement_status IS NULL AND paid_amount >= total");
            DB::statement("UPDATE invoices SET settlement_status = 'partially_paid' WHERE settlement_status IS NULL");
        }
    }

    public function down(): void
    {
        // Data-consistency backfill; reversing it would recreate the drift the
        // issue is about. Intentionally a no-op.
    }
};
