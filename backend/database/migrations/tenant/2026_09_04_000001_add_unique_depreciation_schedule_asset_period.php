<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Issue noovaerp/NoovaERP#64 — depreciation scheduling TOCTOU race.
 *
 * Both calculateDepreciationForPeriod() and generateScheduleForAsset()
 * guarded duplicate schedule rows with a plain read-then-create check,
 * which two concurrent HTTP requests can both pass, double-posting
 * depreciation for the same asset/period. This adds the database-level
 * backstop: at most one depreciation_schedules row per (asset_id,
 * period_date).
 *
 * Hostile re-review (HR64-01): the original version deleted every duplicate
 * beyond the lowest id without checking for a ledger effect. Now:
 *   - a group where exactly one row is ledger-connected (journal_entry_id
 *     set or is_posted) keeps THAT row and removes only unposted noise;
 *   - a group where none is posted keeps the lowest id;
 *   - a group where MORE THAN ONE row is ledger-connected fails closed with
 *     the row ids so a human reconciles — never silently deletes posted rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->tableExists()) {
            return;
        }

        $groups = DB::select('
            SELECT asset_id, period_date, COUNT(*) AS duplicate_count
            FROM depreciation_schedules
            GROUP BY asset_id, period_date
            HAVING COUNT(*) > 1
        ');

        foreach ($groups as $group) {
            $rows = DB::select('
                SELECT id, journal_entry_id, is_posted
                FROM depreciation_schedules
                WHERE asset_id = ? AND period_date = ?
                ORDER BY id
            ', [$group->asset_id, $group->period_date]);

            $posted = array_values(array_filter(
                $rows,
                fn ($row) => $row->journal_entry_id !== null || (bool) $row->is_posted
            ));

            if (count($posted) > 1) {
                throw new \RuntimeException(sprintf(
                    'DEPRECIATION_SCHEDULE_DUPLICATES_NEED_RECONCILIATION: asset %d period %s has %d ledger-connected rows (ids: %s). '
                    .'Resolve these duplicates manually (reverse the erroneous postings) before re-running this migration.',
                    (int) $group->asset_id,
                    (string) $group->period_date,
                    count($posted),
                    implode(', ', array_column($posted, 'id')),
                ));
            }

            $survivorId = $posted[0]->id ?? $rows[0]->id;

            $deleteIds = array_values(array_filter(
                array_map(fn ($row) => (int) $row->id, $rows),
                fn (int $id) => $id !== (int) $survivorId
            ));

            if ($deleteIds !== []) {
                DB::table('depreciation_schedules')->whereIn('id', $deleteIds)->delete();

                Log::warning('depreciation_schedules dedupe (issue #64): unposted duplicates removed', [
                    'asset_id' => $group->asset_id,
                    'period_date' => (string) $group->period_date,
                    'survivor_id' => $survivorId,
                    'deleted_ids' => $deleteIds,
                ]);
            }
        }

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

    private function tableExists(): bool
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('depreciation_schedules')) {
            return false;
        }

        return \Illuminate\Support\Facades\Schema::hasColumn('depreciation_schedules', 'journal_entry_id')
            && \Illuminate\Support\Facades\Schema::hasColumn('depreciation_schedules', 'is_posted');
    }
};
