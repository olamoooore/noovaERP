<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Issue noovaerp/NoovaERP#67 — second-half of the #61 fix tightened.
 *
 * The first version (commit `b44f99f9`) checked only whether ANY tenant
 * schema had the table and silently dropped the central copy. If
 * `tenants:migrate` failed partway (one tenant schema errored out),
 * subsequent re-runs of the central migrations could drop the central
 * table while some tenants were never given their own copy — silent
 * total-outage for those tenants.
 *
 * This version replaces the "any schema has it" check with a
 * completeness check: every active tenant schema must have the table
 * before the central copy is dropped. Incomplete roll-outs surface as
 * a loud runtime exception that fails the migration, so a mis-ordered
 * or partially-failed deploy is visible at deploy time instead of
 * silently degrading affected tenants.
 *
 * Scope: ACC-005 settlement-batch tables (ACC-005A payment_executions,
 * ACC-005B settlements, allocations, unapplied_cash).
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
        $expected = $this->expectedTenantSchemas();

        foreach (self::TABLES as $table) {
            $present = $this->tenantSchemasWith($table);

            $missing = array_values(array_diff($expected, $present));

            if (! empty($missing)) {
                // LOUD FAIL — incomplete roll-out must NOT silently
                // delete the shared central copy. Re-run
                // `tenants:migrate` to completion (or restore from the
                // pre-deploy backup) before re-running this migration.
                throw new \RuntimeException(sprintf(
                    'Issue #67: refusing to drop central public.%s — '
                    . '%d tenant schema(s) are missing their copy '
                    . '(incomplete tenants:migrate roll-out): %s. '
                    . 'Re-run `php artisan tenants:migrate` to '
                    . 'completion before re-running `php artisan migrate`.',
                    $table,
                    count($missing),
                    implode(', ', $missing),
                ));
            }

            DB::statement("DROP TABLE IF EXISTS public.{$table}");
        }
    }

    public function down(): void
    {
        // Intentionally does not recreate central tables: rolling back
        // would resurrect the cross-tenant leakage vector (#61). To
        // revert, restore from the pre-deploy backup taken before
        // tenants:migrate + this migration.
    }

    /**
     * Active tenant schema names, resolved through the canonical tenancy
     * resolver — `Tenant::getSchemaName()` (`$tenant->schema_name` when set,
     * else the tenant key). This is the exact function registered with
     * Stancl's DatabaseConfig (`TenancyServiceProvider::
     * configureTenantDatabaseNames()`), so `id == schema` is NOT assumed
     * (hostile re-review HR67-01: tenants provisioned with a custom
     * schema_name were being checked against their id-named schema).
     *
     * @return string[]
     */
    private function expectedTenantSchemas(): array
    {
        return \App\Models\Tenant::query()
            ->get()
            ->map(fn ($tenant) => trim((string) $tenant->getSchemaName()))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return string[]
     */
    private function tenantSchemasWith(string $table): array
    {
        return DB::table('information_schema.tables')
            ->where('table_name', $table)
            ->where('table_schema', '!=', 'public')
            ->pluck('table_schema')
            ->map(fn ($s) => (string) $s)
            ->all();
    }
};
