<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MRD: invoice-standardized-flow-mrd.md §5.5 — allow approval withdrawal.
 *
 * The platform approval engine is authoritative; this only widens the status
 * CHECK constraint to admit 'withdrawn' for invoice approvals that are pulled
 * back before posting. DDL is schema-qualified (tenant-migration search_path
 * falls back to public — see 2026-09-21 migration DDL leak incident).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->tableExists('approval_requests')) {
            return;
        }

        $schema = $this->quotedSchema();

        DB::statement("ALTER TABLE {$schema}.approval_requests DROP CONSTRAINT IF EXISTS approval_requests_status_check");
        DB::statement(
            "ALTER TABLE {$schema}.approval_requests ADD CONSTRAINT approval_requests_status_check "
            ."CHECK (status::text = ANY (ARRAY['pending','approved','rejected','expired','withdrawn']::text[]))"
        );
    }

    public function down(): void
    {
        if (! $this->tableExists('approval_requests')) {
            return;
        }

        $schema = $this->quotedSchema();

        DB::statement("ALTER TABLE {$schema}.approval_requests DROP CONSTRAINT IF EXISTS approval_requests_status_check");
        DB::statement("UPDATE {$schema}.approval_requests SET status = 'expired' WHERE status = 'withdrawn'");
        DB::statement(
            "ALTER TABLE {$schema}.approval_requests ADD CONSTRAINT approval_requests_status_check "
            ."CHECK (status::text = ANY (ARRAY['pending','approved','rejected','expired']::text[]))"
        );
    }

    private function tableExists(string $table): bool
    {
        return (bool) DB::scalar(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ? LIMIT 1',
            [$table]
        );
    }

    private function quotedSchema(): string
    {
        $schema = DB::selectOne('SELECT current_schema() AS s')->s;

        return '"'.str_replace('"', '""', $schema).'"';
    }
};
