<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #115 (BLOCKER) — contact_merge_history.merged_by type mismatch.
 *
 * The create_contact_duplicate_and_merge_tables migration declared
 * `merged_by` as uuid, but ContactMasterService::merge() passes the acting
 * user's id (`$request->user()->id`), which is a bigint on `users.id`. Every
 * merge attempt that got past the (separately fixed) autoload failure then
 * failed with SQLSTATE[22P02] "invalid input syntax for type uuid: 2".
 *
 * The merge endpoint never succeeded before (the autoload bug guaranteed a
 * fatal Error first), so contact_merge_history is empty in every environment
 * and this type change is non-destructive. Convert to bigint to match
 * users.id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contact_merge_history')) {
            return;
        }
        if (! Schema::hasColumn('contact_merge_history', 'merged_by')) {
            return;
        }

        // Drop the index that referenced the uuid-typed column, change the type,
        // then recreate the index on the integer column.
        DB::statement('DROP INDEX IF EXISTS contact_merge_history_merged_by_index');

        DB::statement('
            ALTER TABLE contact_merge_history
            ALTER COLUMN merged_by TYPE bigint
            USING (
                CASE
                    WHEN merged_by IS NULL THEN NULL
                    WHEN merged_by::text ~ \'^[0-9]+$\' THEN merged_by::text::bigint
                    ELSE NULL
                END
            )
        ');

        DB::statement('CREATE INDEX IF NOT EXISTS contact_merge_history_merged_by_index ON contact_merge_history (merged_by)');
    }

    public function down(): void
    {
        // Forward-only for this corrective migration — reverting to uuid would
        // re-introduce the type mismatch. Intentionally empty.
    }
};
