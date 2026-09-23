<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #70 hostile-review remediation for ACC-001 Opening Balance:
 *
 * - HR70-07/08: give every batch a UNIQUE integer posting_document_id so the
 *   ACC-002 JournalEntryLink is bound to a real per-batch source identity
 *   (not the previous `documentId = 0` that collided across all batches).
 *   Backed by a Postgres sequence; unique across batches per source version.
 * - HR70-10: add supersedes_batch_id to model a posted-source correction /
 *   supersession lineage (successor → the batch it replaces).
 * - HR70-02: enforce economic-baseline uniqueness at the DATABASE level with a
 *   partial unique index over the controlled scope for scope-blocking statuses,
 *   so two concurrent creations for the same scope cannot both insert.
 *
 * Idempotent: every statement guards on existence. Nullable columns + sequence
 * make this safe to run against already-populated tenants.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('opening_balance_batches')) {
            return;
        }

        // Sequence + column for unique ACC-002 source identity (HR70-07).
        DB::statement('CREATE SEQUENCE IF NOT EXISTS opening_balance_posting_document_id_seq');

        if (! Schema::hasColumn('opening_balance_batches', 'posting_document_id')) {
            DB::statement('ALTER TABLE opening_balance_batches ADD COLUMN posting_document_id bigint NULL');
        }

        if (! Schema::hasColumn('opening_balance_batches', 'supersedes_batch_id')) {
            DB::statement('ALTER TABLE opening_balance_batches ADD COLUMN supersedes_batch_id uuid NULL');
        }

        // Plain text scope key (tenant|entity|book|cutover|population) used by
        // the DB-backed baseline uniqueness index. Computed in the application
        // (OpeningBalanceService) so the index needs no non-immutable casts.
        if (! Schema::hasColumn('opening_balance_batches', 'scope_key')) {
            DB::statement("ALTER TABLE opening_balance_batches ADD COLUMN scope_key varchar(300) NULL");
        }

        // Backfill existing rows with distinct sequence values.
        DB::statement(
            'UPDATE opening_balance_batches
             SET posting_document_id = nextval(\'opening_balance_posting_document_id_seq\')
             WHERE posting_document_id IS NULL'
        );

        // Unique index on the ACC-002 source identity (HR70-07/08).
        if (! $this->indexExists('opening_balance_batches_posting_document_id_uk')) {
            DB::statement(
                'CREATE UNIQUE INDEX opening_balance_batches_posting_document_id_uk
                 ON opening_balance_batches (posting_document_id)
                 WHERE posting_document_id IS NOT NULL'
            );
        }

        // HR70-02: database-backed economic-baseline uniqueness. At most one
        // scope-blocking (active or POSTED) batch per controlled scope.
        // COALESCE sentinel normalises nullable entity/book so NULL and a
        // value never accidentally collide or silently allow duplicates.
        if (! $this->indexExists('ob_baseline_scope_unique_uk')) {
            // HR70-02: at most one scope-blocking (active or POSTED) baseline
            // per controlled scope, enforced at the DB layer so concurrent
            // creations cannot both insert. scope_key is a plain text column
            // (no non-immutable casts). Null scope_key rows never collide.
            $sql = "CREATE UNIQUE INDEX ob_baseline_scope_unique_uk "
                . "ON opening_balance_batches (scope_key) "
                . "WHERE status IN (" . $this->statusInList() . ") AND scope_key IS NOT NULL";
            DB::statement($sql);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('opening_balance_batches')) {
            return;
        }
        // Schema-qualified: unqualified DROP INDEX/SEQUENCE can leak to public via search_path.
        $schema = DB::selectOne('SELECT current_schema() AS s')->s;
        DB::statement("DROP INDEX IF EXISTS \"{$schema}\".opening_balance_batches_posting_document_id_uk");
        DB::statement("DROP INDEX IF EXISTS \"{$schema}\".ob_baseline_scope_unique_uk");
        DB::statement('ALTER TABLE opening_balance_batches DROP COLUMN IF EXISTS supersedes_batch_id');
        DB::statement('ALTER TABLE opening_balance_batches DROP COLUMN IF EXISTS scope_key');
        DB::statement('ALTER TABLE opening_balance_batches DROP COLUMN IF EXISTS posting_document_id');
        DB::statement("DROP SEQUENCE IF EXISTS \"{$schema}\".opening_balance_posting_document_id_seq");
    }

    private function indexExists(string $index): bool
    {
        return (bool) DB::scalar(
            'SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND indexname = ? LIMIT 1',
            [$index]
        );
    }

    /**
     * Quoted IN(...) list of the canonical scope-blocking statuses, sourced
     * from the enum so the index and application logic never diverge.
     */
    private function statusInList(): string
    {
        $statuses = \Modules\AccountingFoundation\Enums\OpeningBalanceStatus::scopeBlockingValues();
        $quoted = array_map(
            fn ($s) => DB::connection()->getPdo()->quote($s),
            $statuses
        );

        return implode(',', $quoted);
    }
};
