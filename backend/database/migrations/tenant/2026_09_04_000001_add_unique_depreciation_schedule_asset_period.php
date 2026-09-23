<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Issue noovaerp/NoovaERP#64 — depreciation scheduling TOCTOU race.
 *
 * Both calculateDepreciationForPeriod() and generateScheduleForAsset()
 * guarded duplicate schedule rows with a plain read-then-create check,
 * which two concurrent HTTP requests can both pass, double-posting
 * depreciation for the same asset/period. This adds the database-level
 * backstop: at most one depreciation_schedules row per (asset_id,
 * period_date). Existing duplicates (if any) are collapsed to the
 * earliest row before the constraint is applied.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Collapse pre-existing duplicates, keeping the lowest id per pair.
        DB::statement('
            DELETE FROM depreciation_schedules a
            USING depreciation_schedules b
            WHERE a.id > b.id
              AND a.asset_id = b.asset_id
              AND a.period_date = b.period_date
        ');

        DB::statement('
            CREATE UNIQUE INDEX IF NOT EXISTS depreciation_schedules_asset_period_unique
            ON depreciation_schedules (asset_id, period_date)
        ');
    }

    public function down(): void
    {
        // Schema-qualified: unqualified DROP INDEX can leak to public via search_path.
        $schema = DB::selectOne('SELECT current_schema() AS s')->s;
        DB::statement("DROP INDEX IF EXISTS \"{$schema}\".depreciation_schedules_asset_period_unique");
    }
};
