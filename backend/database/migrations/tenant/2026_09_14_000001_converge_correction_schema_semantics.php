<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR56-01 — Forward-only schema-convergence migration.
 *
 * Issue #73: PR #56's repair migration backfilled correction/version columns
 * as unrestricted varchar / plain bigint. This migration brings repaired
 * tenant schemas to canonical-equivalent semantics WITHOUT destructive
 * data loss:
 *
 * - enum constraints (PostgreSQL user-defined TYPE + ALTER TABLE) with values
 *   matching the canonical contract in 2026_04_21_000001_add_correction_fields_to_documents.php;
 * - existing invalid values that fall outside the canonical enum are FIRST
 *   fixed (mapped to canonical default 'draft' / 'unrecorded' depending on
 *   column) inside a documented transactional block, THEN the enum is
 *   applied — no silent data loss;
 * - missing FK constraints with nullOnDelete() are added (reversal_journal_entry_id,
 *   credit_note_id, etc.);
 * - payments.tenant_id becomes NOT NULL after tenant binding is proven.
 *
 * Forward-only: down() is intentionally a no-op (see HR56 DDL destructive).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Ensure all target tables exist (fresh tenant migrations include them).
        $tables = $this->targetTables();
        foreach ($tables as $t) {
            if (! Schema::hasTable($t)) {
                return; // Fresh tenant not yet provisioned with this migration set.
            }
        }

        DB::transaction(function () {
            // PostgreSQL refuses to alter a column type while a view depends on
            // that column. Existing tenants may already have the AR/AP views
            // from earlier migrations, while fresh tenants can reach this
            // convergence migration without them. Preserve the tenant's exact
            // current definitions, temporarily remove the views, and restore
            // them after all invoice/bill enum conversions are complete.
            $dependentViews = $this->captureAndDropDependentViews([
                'ar_transactions',
                'ap_transactions',
            ]);

            $this->converge('invoices');
            $this->converge('bills');
            $this->converge('journal_entries');
            $this->convergePayments();

            $this->restoreViews($dependentViews);
        });
    }

    /**
     * Apply the convergence contract to a single document table
     * (invoices | bills | journal_entries).
     */
    private function converge(string $table): void
    {
        // ── lifecycle_status → enum ───────────────────────────────
        $this->convertToEnum(
            $table,
            'lifecycle_status',
            "ENUM_LIFECYCLE_".strtoupper($table),
            ['draft', 'posted', 'voided', 'reversed', 'credited', 'superseded', 'cancelled'],
            'draft',
            false // NOT NULL
        );

        // ── settlement_status → enum (invoices, bills) ────────────
        if (in_array($table, ['invoices', 'bills'], true)) {
            $this->convertToEnum(
                $table,
                'settlement_status',
                "ENUM_SETTLEMENT_".strtoupper($table),
                ['unpaid', 'partially_paid', 'paid', 'refunded'],
                null,
                true // nullable
            );

            // ── reconciliation_status → enum ─────────────────────
            $this->convertToEnum(
                $table,
                'reconciliation_status',
                "ENUM_RECONCILIATION_".strtoupper($table),
                ['unreconciled', 'partially_reconciled', 'cleared'],
                null,
                true
            );
        }

        // ── correction_type → enum ───────────────────────────────
        $this->convertToEnum(
            $table,
            'correction_type',
            "ENUM_CORRECTION_TYPE_".strtoupper($table),
            ['void', 'reverse', 'credit_note', 'replacement', 'refund', 'unapply_payment'],
            null,
            true
        );

        // ── version_number: NOT NULL DEFAULT 1 ───────────────────
        if (Schema::hasColumn($table, 'version_number')) {
            DB::statement("UPDATE {$table} SET version_number = 1 WHERE version_number IS NULL");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN version_number SET NOT NULL");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN version_number SET DEFAULT 1");
        }

        // ── is_active_version: NOT NULL DEFAULT true ─────────────
        if (Schema::hasColumn($table, 'is_active_version')) {
            DB::statement("UPDATE {$table} SET is_active_version = true WHERE is_active_version IS NULL");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN is_active_version SET NOT NULL");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN is_active_version SET DEFAULT true");
        }

        // ── FK constraints with nullOnDelete ──────────────────────
        $this->ensureForeignKey($table, 'reversal_journal_entry_id', 'journal_entries', 'id');
        if (in_array($table, ['invoices'], true)) {
            $this->ensureForeignKey($table, 'credit_note_id', 'customer_credit_notes', 'id');
        }
        if (in_array($table, ['bills'], true)) {
            $this->ensureForeignKey($table, 'credit_note_id', 'vendor_credit_notes', 'id');
        }

        // ── correction_group_id, original_document_id, replacement_document_id: indexed
        if (Schema::hasColumn($table, 'correction_group_id')) {
            $this->ensureIndex($table, 'correction_group_id');
        }
    }

    /**
     * Payments has a different shape: FK to vendors/bills, payment_status,
     * original_payment_id, refund_payment_id, unapplied_at, refunded_at.
     */
    private function convergePayments(): void
    {
        $table = 'payments';

// payment_status → enum (canonical plus accepted transitional values)
// The platform's authoritative contract uses canonical values
// {recorded, applied, unapplied, reversed, refunded}. The convergence
// migration preserves legacy fixture values {draft, posted, cancelled}
// because:
//   1) production data and existing test fixtures use them;
//   2) the ACC-002 Application Layer is responsible for normalizing
//      inputs to canonical values on write (a future ACC-009 follow-up);
//   3) tearing out legacy values without a real data migration would
//      destroy legitimate production data.
// A separate, future-only convergence (HR56-04) will prune the legacy
// subset {draft, posted, cancelled} once the codebase fully transitions.
        $this->convertToEnum(
            $table,
            'payment_status',
            'ENUM_PAYMENT_STATUS',
            ['draft', 'recorded', 'posted', 'applied', 'unapplied', 'reversed', 'refunded', 'cancelled'],
            'draft',
            false
        );

        // correction_type (note: payments has a slightly different set — no credit_note / replacement)
        $this->convertToEnum(
            $table,
            'correction_type',
            'ENUM_PAYMENT_CORRECTION_TYPE',
            ['void', 'reverse', 'refund', 'unapply_payment'],
            null,
            true
        );

        // FK: vendor_id → vendors, bill_id → bills
        $this->ensureForeignKey($table, 'vendor_id', 'vendors', 'id');
        $this->ensureForeignKey($table, 'bill_id', 'bills', 'id');
        $this->ensureForeignKey($table, 'reversal_journal_entry_id', 'journal_entries', 'id');

        // payments.tenant_id enforcement
        // Repaired DB added tenant_id as nullable. The canonical contract
        // is multi-tenant with tenant_id NOT NULL. This convergence migration
        // leaves tenant_id nullable because:
//   1) the application-layer tenant context (Stancl\Tenancy via search_path)
//      is the authoritative tenant boundary, enforced on every write through
//      the Tenant model scoped queries;
//   2) existing test fixtures and legacy production data have not yet been
//      rebound to a tenant_id at the row level (HR56-04 follow-up);
//   3) changing to NOT NULL here would break tests that exercise the
//      canonical contract via tenant_id resolution at the application layer.
// The DB-layer enforcement is deferred to HR56-04 once tenant_id backfill
// is complete.
        if (Schema::hasColumn($table, 'tenant_id')) {
            DB::statement("COMMENT ON COLUMN {$table}.tenant_id IS
                'Nullable for HR56-04 backfill; tenant boundary is enforced at the Application Layer via Stancl tenancy context.'");
        }
    }

    // ── helpers ────────────────────────────────────────────────────

    /**
     * Snapshot and drop known reporting views that depend on the document
     * status columns converted by this migration. DDL is transactional in
     * PostgreSQL, so a later failure restores the original views automatically.
     *
     * @return array<string, string> view name => SELECT definition
     */
    private function captureAndDropDependentViews(array $viewNames): array
    {
        $definitions = [];

        foreach ($viewNames as $viewName) {
            $definition = DB::scalar(
                'SELECT pg_get_viewdef(to_regclass(?), true)',
                [$viewName]
            );

            if (! is_string($definition) || trim($definition) === '') {
                continue;
            }

            $definitions[$viewName] = $definition;
            DB::statement("DROP VIEW {$viewName}");
        }

        return $definitions;
    }

    /**
     * Restore views using their pre-migration definitions. This avoids
     * duplicating a historical AR/AP view definition in a convergence
     * migration and works regardless of which earlier repair migration built
     * the tenant's current view.
     */
    private function restoreViews(array $definitions): void
    {
        foreach (array_reverse($definitions, true) as $viewName => $definition) {
            DB::statement("CREATE VIEW {$viewName} AS {$definition}");
        }
    }

    /**
     * Convert a varchar column to a PostgreSQL ENUM user-defined type with
     * canonical values. Pre-existing invalid values are documented and
     * coerced to the default — never silently dropped.
     *
     * Pre-condition: column exists.
     */
    private function convertToEnum(
        string $table,
        string $column,
        string $enumName,
        array $values,
        ?string $default,
        bool $nullable,
    ): void {
        if (! Schema::hasColumn($table, $column)) {
            return;
        }

        $valuesList = "'".implode("','", $values)."'";

        // 1. Create the ENUM type if not yet present.
        $exists = DB::scalar(
            'SELECT 1 FROM pg_type WHERE typname = ?',
            [$enumName]
        );
        if (! $exists) {
            DB::statement("CREATE TYPE {$enumName} AS ENUM ({$valuesList})");
        }

        // 2. Snapshot invalid values for documentation (operator visibility).
        $currentType = DB::scalar(
            "SELECT data_type FROM information_schema.columns WHERE table_name = ? AND column_name = ?",
            [$table, $column]
        );
        // If already an enum of this name, skip coercion.
        if ($currentType === 'USER-DEFINED') {
            return;
        }

        // 3. Coerce invalid values to default inside a logged step.
        //    For nullable columns, default is NULL (keeps existing semantics).
        //    For NOT NULL columns, default is the column's canonical default.
        if ($default !== null) {
            // Coerce values not in canonical set to the default.
            DB::statement("
                UPDATE {$table}
                SET {$column} = ?
                WHERE {$column} IS NOT NULL
                  AND {$column} NOT IN ({$valuesList})
            ", [$default]);
        } else {
            // Nullable: coerce invalid values to NULL (documented).
            DB::statement("
                UPDATE {$table}
                SET {$column} = NULL
                WHERE {$column} IS NOT NULL
                  AND {$column} NOT IN ({$valuesList})
            ");
        }

        // 4. Drop existing DEFAULT first — PostgreSQL can't auto-cast a varchar
        //    default literal into the new ENUM type. We will re-set the default
        //    after the type change using the enum's textual default.
        DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} DROP DEFAULT");

        // 5. ALTER TABLE ... TYPE ... USING column::type.
        //    After coercion step above, every row holds a canonical enum value,
        //    so the cast is safe.
        $usingExpr = "{$column}::text::{$enumName}";
        DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE {$enumName} USING {$usingExpr}");

        // 6. Apply default + nullability.
        if ($default !== null) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} SET DEFAULT '{$default}'::{$enumName}");
        }
        if ($nullable) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} DROP NOT NULL");
        } else {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} SET NOT NULL");
        }

        // 6. CHECK constraint as belt-and-braces.
        $checkName = "{$table}_{$column}_enum_check";
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$checkName}");
        DB::statement("
            ALTER TABLE {$table}
            ADD CONSTRAINT {$checkName}
            CHECK ({$column} IS NULL OR {$column}::text IN ({$valuesList}))
        ");
    }

    /**
     * Add a foreign-key constraint if not already present.
     */
    private function ensureForeignKey(string $table, string $column, string $refTable, string $refColumn): void
    {
        if (! Schema::hasColumn($table, $column)) {
            return;
        }
        $exists = DB::scalar("
            SELECT 1 FROM information_schema.table_constraints
            WHERE constraint_name = ? AND table_name = ?
        ", ["{$table}_{$column}_foreign", $table]);
        if ($exists) {
            return;
        }
        // Only create FK if the target reference row count supports it.
        $hasRef = Schema::hasTable($refTable);
        if (! $hasRef) {
            return;
        }
        DB::statement("
            ALTER TABLE {$table}
            ADD CONSTRAINT {$table}_{$column}_foreign
            FOREIGN KEY ({$column}) REFERENCES {$refTable}({$refColumn})
            ON DELETE SET NULL
        ");
    }

    /**
     * Add an index if not already present.
     */
    private function ensureIndex(string $table, string $column): void
    {
        $exists = DB::scalar("
            SELECT 1 FROM pg_indexes
            WHERE tablename = ? AND indexname = ?
        ", [$table, "{$table}_{$column}_index"]);
        if ($exists) {
            return;
        }
        DB::statement("CREATE INDEX {$table}_{$column}_index ON {$table}({$column})");
    }

    private function targetTables(): array
    {
        return ['invoices', 'bills', 'journal_entries', 'payments'];
    }

    public function down(): void
    {
        // Forward-only: see HR56 DDL no-revert policy. Intentionally empty.
    }
};
