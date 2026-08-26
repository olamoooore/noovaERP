<?php

namespace App\Console\Concerns;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Pillar 1 P0-11 / Pillar 2 Part 1 §1.3-§1.4:
 *
 * Console commands and the Laravel scheduler run with the connection's
 * default `search_path = 'public'` (per `config/database.php:96`).
 * They are NOT inside the HTTP request pipeline that
 * `EnsureTenantSearchPath` / `SetTenantFromUserPreference` /
 * `SyncTenantUserFromCentralAuth` protect, so any command that
 * touches a tenant-schema-only table will throw
 * `SQLSTATE[42P01]: relation does not exist` the moment it queries
 * its target table — silently failing on every cron run.
 *
 * Per the report's remediation: every command that needs to touch
 * tenant-schema tables must wrap its work in `$tenant->run(fn() => ...)`
 * for each tenant. This trait centralises the iteration pattern so
 * individual commands don't re-implement it (and can't get it wrong).
 *
 * Usage:
 *
 *   class MyCommand extends Command {
 *       use IteratesTenants;
 *       public function handle() {
 *           return $this->forEachTenant(function (Tenant $tenant) {
 *               // ... your tenant-scoped work here ...
 *               // The TenantUserHelper / DB / models are
 *               // schema-bound to $tenant's schema for the
 *               // duration of this closure.
 *           });
 *       }
 *   }
 *
 * Or, for a synchronous, blocking loop (no scheduler async):
 *
 *   return $this->forEachTenantSync(function (Tenant $tenant) { ... });
 */
trait IteratesTenants
{
    /**
     * Iterate over every tenant and run the closure inside each
     * tenant's schema-bound context. The closure receives the Tenant
     * model so callers can use its id, name, etc.
     *
     * If no tenants exist (e.g. fresh install), the closure is
     * never called and a warning is logged.
     *
     * Public so tests (and other consumer classes) can invoke the
     * iteration directly without going through Artisan. The class
     * itself is the only legitimate caller; this is the same
     * public/protected trade-off as Laravel's
     * \Illuminate\Console\Command::handle signature (public so the
     * framework can dispatch).
     *
     * @param  \Closure  $fn  function(Tenant $tenant): int
     * @return int  Total aggregated exit code (0 = all OK,
     *               non-zero = at least one tenant errored)
     */
    public function forEachTenant(\Closure $fn): int
    {
        $totalExit = 0;
        $tenantCount = 0;
        $errorCount = 0;
        $skippedCount = 0;

        // Iterate via the central connection. `Tenant::query()` runs on
        // `public`, which is where the central tenants table lives.
        foreach (Tenant::query()->cursor() as $tenant) {
            // Skip tenants without schemas (e.g., test tenants, newly created tenants)
            $schemaName = $tenant->getSchemaName();
            if (! $this->schemaExists($schemaName)) {
                $skippedCount++;
                Log::info('IteratesTenants: skipping tenant without schema', [
                    'tenant_id' => $tenant->id,
                    'schema' => $schemaName,
                ]);
                continue;
            }

            $tenantCount++;
            try {
                $exit = $tenant->run(function () use ($tenant, $fn) {
                    return $fn($tenant);
                });
                if ($exit !== 0) {
                    $totalExit = max($totalExit, $exit);
                }
            } catch (\Throwable $e) {
                $errorCount++;
                $totalExit = max($totalExit, 1);
                Log::error('Tenant iteration failed', [
                    'tenant_id' => $tenant->id,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if ($tenantCount === 0 && $skippedCount === 0) {
            Log::warning('IteratesTenants: no tenants found; closure never called.');
        } elseif ($tenantCount > 0) {
            Log::info('IteratesTenants: completed', [
                'tenants' => $tenantCount,
                'errors' => $errorCount,
                'skipped' => $skippedCount,
            ]);
        }

        return $totalExit;
    }

    /**
     * Check if a schema exists in the database.
     */
    private function schemaExists(string $schemaName): bool
    {
        $result = \Illuminate\Support\Facades\DB::select(
            "SELECT 1 FROM information_schema.schemata WHERE schema_name = ?",
            [$schemaName]
        );
        return count($result) > 0;
    }

    /**
     * Synchronous variant — returns total exit code.
     * Same semantics as forEachTenant but documents intent.
     */
    public function forEachTenantSync(\Closure $fn): int
    {
        return $this->forEachTenant($fn);
    }
}
