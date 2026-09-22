<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Issue #126 (MEDIUM) — at most one primary CRM contact per customer.
 *
 * CustomerContactSyncService::setPrimaryContact() is the only place that
 * currently enforces this invariant, leaving every other write path
 * (imports, raw updates, future controllers) free to silently create multiple
 * primary contacts. This adds the ADR-003A gap-matrix constraint that was
 * originally specified (CT-VEN-007): a partial unique index on
 * (customer_id) WHERE is_primary AND deleted_at IS NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = '"'.str_replace('"', '""', (string) DB::selectOne('SELECT current_schema() AS s')->s).'"';

        // Idempotent: a partial unique index can't coexist with itself.
        // Schema-qualified: the tenant-migration search_path falls back to
        // public, so an unqualified DROP INDEX can leak into central tables.
        DB::statement("DROP INDEX IF EXISTS {$schema}.customer_contacts_one_primary_per_customer");

        DB::statement("
            CREATE UNIQUE INDEX customer_contacts_one_primary_per_customer
            ON {$schema}.customer_contacts (customer_id)
            WHERE is_primary AND deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        $schema = '"'.str_replace('"', '""', (string) DB::selectOne('SELECT current_schema() AS s')->s).'"';

        DB::statement("DROP INDEX IF EXISTS {$schema}.customer_contacts_one_primary_per_customer");
    }
};
