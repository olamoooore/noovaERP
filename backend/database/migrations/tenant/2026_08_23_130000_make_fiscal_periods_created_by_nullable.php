<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Allow system auto-provisioning of fiscal periods without an actor.
 *
 * FiscalPeriodValidator::validateDate() auto-creates a year of monthly
 * periods the first time anything is posted into a year. In headless
 * contexts (queue jobs, posting engines invoked before any user is
 * seeded, channel imports) there is no authenticated tenant user, and
 * the NOT NULL constraint on fiscal_periods.created_by aborted posting
 * with SQLSTATE[23502]. System-provisioned periods legitimately have no
 * human creator, so the column becomes nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fiscal_periods')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE fiscal_periods ALTER COLUMN created_by DROP NOT NULL');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE fiscal_periods MODIFY created_by BIGINT UNSIGNED NULL');
        }
        // Other drivers: created_by was only enforced NOT NULL on pg/mysql.
    }

    public function down(): void
    {
        if (! Schema::hasTable('fiscal_periods')) {
            return;
        }

        // Only re-enforce the constraint when no NULLs would violate it.
        $hasNulls = DB::table('fiscal_periods')->whereNull('created_by')->exists();
        if ($hasNulls) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE fiscal_periods ALTER COLUMN created_by SET NOT NULL');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE fiscal_periods MODIFY created_by BIGINT UNSIGNED NOT NULL');
        }
    }
};
