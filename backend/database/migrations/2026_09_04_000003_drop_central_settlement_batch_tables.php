<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Issue noovaerp/NoovaERP#61 — second half of the fix.
 *
 * Removes the shared CENTRAL copies of the ACC-005 settlement batch
 * tables once the tenant migration
 * `2026_09_04_000002_move_settlement_batch_tables_to_tenant_schema.php`
 * has created per-tenant versions. Dropping them here means a tenant
 * request can no longer silently fall back to a shared public-schema
 * table via search_path — the cross-tenant leakage vector.
 *
 * Deployment order matters: run `php artisan tenants:migrate` FIRST
 * (creates tenant tables + backfills any tenant rows), then
 * `php artisan migrate` (this drop). Verified on production 2026-09-04:
 * all four central tables contained zero rows, so the backfill is a
 * no-op safeguard, not a data dependency.
 *
 * The tables being dropped are themselves created by now-superseded
 * central migrations (2026_08_16_000009/10/11/12 + 2026_08_25_000001),
 * left in place for fresh-install reproducibility; this migration is
 * the authoritative removal step for existing deployments.
 */
return new class extends Migration
{
    private const TABLES = [
        'unapplied_cash',
        'allocations',
        'settlements',
        'payment_executions',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            // Safety: only drop the central copy when the tenant
            // counterpart is reachable via tenants:migrate — i.e. when
            // at least one tenant schema owns the table.
            $tenantCopyExists = (bool) DB::selectOne(
                "SELECT 1 FROM information_schema.tables
                 WHERE table_name = ? AND table_schema NOT IN ('public') LIMIT 1",
                [$table]
            );

            if ($tenantCopyExists) {
                DB::statement("DROP TABLE IF EXISTS public.{$table}");
            }
        }
    }

    public function down(): void
    {
        // Intentionally does not recreate central tables: rolling back
        // would resurrect the cross-tenant leakage vector (#61). To
        // revert, restore from the pre-deploy backup taken before
        // tenants:migrate + this migration.
    }
};
