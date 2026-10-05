<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Issue #768 (A8) — fiscal periods must be scoped to a legal entity.
 *
 * 1. `fiscal_periods.legal_entity_id` / `accounting_book_id` were added as
 *    uuid while `legal_entities.id` / `entity_books.id` are bigint, so no
 *    row could ever reference a real entity. The columns are nullable and
 *    were never backfilled (see 2026_08_29_000004); they are re-typed to
 *    match their masters.
 * 2. `code` and `(period_start, period_end)` were globally unique, which
 *    blocks two entities with different year ends from having periods for
 *    the same dates. Uniqueness is replaced by partial indexes: legacy
 *    entity-less rows keep the global guarantees, entity rows are unique
 *    per entity.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The uuid columns could never hold a valid legal_entities.id /
        // entity_books.id (bigint). Refuse loudly if anything was written
        // rather than silently nulling it; otherwise drop the type.
        $nonNull = DB::table('fiscal_periods')->whereNotNull('legal_entity_id')->count()
            + DB::table('fiscal_periods')->whereNotNull('accounting_book_id')->count()
            + DB::table('lock_dates')->whereNotNull('legal_entity_id')->count()
            + DB::table('lock_dates')->whereNotNull('accounting_book_id')->count();

        if ($nonNull > 0) {
            throw new \RuntimeException(
                "Cannot scope fiscal periods to entities: {$nonNull} row(s) carry a uuid legal_entity_id/accounting_book_id that cannot reference the bigint masters. Backfill them first."
            );
        }

        DB::statement('ALTER TABLE fiscal_periods ALTER COLUMN legal_entity_id TYPE bigint USING NULL');
        DB::statement('ALTER TABLE fiscal_periods ALTER COLUMN accounting_book_id TYPE bigint USING NULL');
        DB::statement('ALTER TABLE lock_dates ALTER COLUMN legal_entity_id TYPE bigint USING NULL');
        DB::statement('ALTER TABLE lock_dates ALTER COLUMN accounting_book_id TYPE bigint USING NULL');

        DB::statement('ALTER TABLE fiscal_periods DROP CONSTRAINT IF EXISTS fiscal_periods_code_unique');
        DB::statement('ALTER TABLE fiscal_periods DROP CONSTRAINT IF EXISTS fiscal_periods_period_start_period_end_unique');

        DB::statement('CREATE UNIQUE INDEX fiscal_periods_code_global_unique ON fiscal_periods (code) WHERE legal_entity_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX fiscal_periods_entity_code_unique ON fiscal_periods (legal_entity_id, code) WHERE legal_entity_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX fiscal_periods_global_dates_unique ON fiscal_periods (period_start, period_end) WHERE legal_entity_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX fiscal_periods_entity_dates_unique ON fiscal_periods (legal_entity_id, period_start, period_end) WHERE legal_entity_id IS NOT NULL');

        DB::statement('CREATE INDEX fiscal_periods_entity_dates_idx ON fiscal_periods (legal_entity_id, period_start, period_end)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS fiscal_periods_entity_dates_idx');
        DB::statement('DROP INDEX IF EXISTS fiscal_periods_entity_dates_unique');
        DB::statement('DROP INDEX IF EXISTS fiscal_periods_global_dates_unique');
        DB::statement('DROP INDEX IF EXISTS fiscal_periods_entity_code_unique');
        DB::statement('DROP INDEX IF EXISTS fiscal_periods_code_global_unique');

        DB::statement('ALTER TABLE fiscal_periods ADD CONSTRAINT fiscal_periods_code_unique UNIQUE (code)');
        DB::statement('ALTER TABLE fiscal_periods ADD CONSTRAINT fiscal_periods_period_start_period_end_unique UNIQUE (period_start, period_end)');

        // Entity ids are bigint now; a rollback cannot rewrite them to uuid,
        // so the context columns are emptied (they were always nullable).
        DB::statement('ALTER TABLE fiscal_periods ALTER COLUMN legal_entity_id TYPE uuid USING NULL');
        DB::statement('ALTER TABLE fiscal_periods ALTER COLUMN accounting_book_id TYPE uuid USING NULL');
        DB::statement('ALTER TABLE lock_dates ALTER COLUMN legal_entity_id TYPE uuid USING NULL');
        DB::statement('ALTER TABLE lock_dates ALTER COLUMN accounting_book_id TYPE uuid USING NULL');
    }
};
