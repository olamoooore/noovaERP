<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Issue #91 (CRITICAL, AUDIT-1) — Defence-in-depth DB-level immutability for
 * posting_audit_logs.
 *
 * The application-layer `boot()` guard in `App\Models\PostingAuditLog`
 * (matching the sibling `App\Models\AuditLog` pattern) blocks Eloquent
 * ->update() and ->delete(). However, an attacker who bypasses Eloquent via
 * raw `DB::table()` calls or direct SQL is not bound by the model guard.
 *
 * This migration installs a Postgres trigger that raises an exception on any
 * UPDATE or DELETE statement against `posting_audit_logs`, regardless of
 * caller. Forward-only: down() drops the trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Trigger function: raises a runtime exception when any UPDATE or
        // DELETE is attempted against posting_audit_logs.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION posting_audit_logs_block_modify()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'posting_audit_logs is append-only: % on this table is not permitted', TG_OP
                    USING ERRCODE = 'P0001';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        // Install trigger on UPDATE (BEFORE) — refuses any UPDATE.
        DB::statement('DROP TRIGGER IF EXISTS posting_audit_logs_no_update ON posting_audit_logs');
        DB::statement(<<<'SQL'
            CREATE TRIGGER posting_audit_logs_no_update
            BEFORE UPDATE ON posting_audit_logs
            FOR EACH ROW
            EXECUTE FUNCTION posting_audit_logs_block_modify();
        SQL);

        // Install trigger on DELETE (BEFORE) — refuses any DELETE.
        // TRUNCATE is intentionally not blocked here because tenant-scoped
        // cleanup operations use TRUNCATE; if TRUNCATE becomes a concern,
        // add a STATEMENT-level trigger on TRUNCATE.
        DB::statement('DROP TRIGGER IF EXISTS posting_audit_logs_no_delete ON posting_audit_logs');
        DB::statement(<<<'SQL'
            CREATE TRIGGER posting_audit_logs_no_delete
            BEFORE DELETE ON posting_audit_logs
            FOR EACH ROW
            EXECUTE FUNCTION posting_audit_logs_block_modify();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS posting_audit_logs_no_update ON posting_audit_logs');
        DB::statement('DROP TRIGGER IF EXISTS posting_audit_logs_no_delete ON posting_audit_logs');
        DB::statement('DROP FUNCTION IF EXISTS posting_audit_logs_block_modify()');
    }
};
