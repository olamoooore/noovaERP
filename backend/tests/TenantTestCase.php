<?php

namespace Tests;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Tenancy;

/**
 * TenantTestCase — Base test class for any test that needs tenant schema tables.
 *
 * Runs ALL tenant-directory migrations (core + module) so that tables like
 * `vendors`, `chart_of_accounts`, `journal_entries`, `invoices`, etc. exist
 * in the test database before any test method runs.
 *
 * Usage:
 *   class MyTest extends \Tests\TenantTestCase { ... }
 *
 * This replaces the ad-hoc approaches previously used across modules:
 *   - HrmTestCase (db:wipe + 2 paths)
 *   - ProductImportTest (12 cherry-picked files)
 *   - MultiEntityIntegrationTest (single core path)
 */
abstract class TenantTestCase extends TestCase
{
    use RefreshDatabase;

    protected ?Tenant $boundTestTenant = null;

    /**
     * Override RefreshDatabase's migration logic to include tenant paths.
     *
     * The default RefreshDatabase trait only runs `database/migrations/` (the
     * central/landlord migrations). Tenant schema tests need the tables defined
     * in `database/migrations/tenant/` and in each module's tenant migration
     * directory.
     */
    protected function refreshTestDatabase(): void
    {
        if (!RefreshDatabaseState::$migrated) {
            $this->runTenantMigrations();
            RefreshDatabaseState::$migrated = true;
        }

        $this->beginDatabaseTransaction();
    }

    /**
     * Run all tenant migration paths.
     *
     * Mirrors the paths defined in `config/tenancy.php` → `migration_parameters`
     * so the test database matches what a real tenant schema looks like.
     *
     * NOTE: Uses --path for the central step to prevent Laravel 11's default
     * recursive scan of database/migrations/ which would pick up the
     * database/migrations/tenant/ subdirectory. Module tenant paths are then
     * run explicitly in order. Communication module's central migration is
     * included since --path bypasses loadMigrationsFrom() discovery.
     */
    protected function runTenantMigrations(): void
    {
        // Wipe first to avoid stale state from previous runs
        Artisan::call('db:wipe', ['--force' => true]);

        // Gather the basenames of all tenant migration files so we can exclude
        // any file from the central directory that shares the same name.
        // Duplicates cause the tenant copy to be silently skipped (Laravel
        // records the migration name in the migrations table on first run and
        // considers it already-ran for subsequent paths).
        $tenantDir = database_path('migrations/tenant');
        $tenantFileNames = is_dir($tenantDir)
            ? array_map('basename', glob($tenantDir . '/*.php'))
            : [];

        // Collect only central-only migration files (not duplicated in tenant/).
        $centralDir = database_path('migrations');
        $centralFiles = array_values(array_filter(
            glob($centralDir . '/*.php'),
            fn(string $f) => !in_array(basename($f), $tenantFileNames, true),
        ));

        // Run central migrations (users, tenants, etc.) using individual file
        // paths + the Communication module's central migration directory.
        Artisan::call('migrate', [
            '--path' => array_merge(
                $centralFiles,
                [base_path('modules/Communication/database/migrations')],
            ),
            '--realpath' => true,
            '--force' => true,
        ]);

        // Tenant migration paths — run sequentially so FK ordering is correct.
        // Core tenant migrations go first, then module-specific ones.
        $tenantPaths = $this->getTenantMigrationPaths();

        foreach ($tenantPaths as $path) {
            $realPath = base_path($path);
            if (is_dir($realPath)) {
                Artisan::call('migrate', [
                    '--path' => [$realPath],
                    '--realpath' => true,
                    '--force' => true,
                ]);
            }
        }
    }

    /**
     * Return all tenant migration paths, mirroring tenancy.php config.
     *
     * Override this in subclasses to run only a subset of paths when the full
     * set is not needed (speeds up tests that only touch a single module).
     */
    protected function getTenantMigrationPaths(): array
    {
        return [
            'database/migrations/tenant',
            'modules/EventManagement/Database/Migrations/tenant',
            'modules/FinancialReporting/database/migrations/tenant',
            'modules/HRM/database/migrations/tenant',
            'modules/OCRBookkeeping/database/migrations/tenant',
            'modules/POS/database/migrations/tenant',
            'modules/Ecommerce/database/migrations/tenant',
            'modules/AI/database/migrations/tenant',
            'modules/FieldService/database/migrations/tenant',
            'modules/WebsiteBuilder/database/migrations/tenant',
            'modules/IoT/database/migrations/tenant',
            'modules/Loyalty/database/migrations/tenant',
        ];
    }

    /**
     * Verify that critical tenant tables exist.
     * Call this in setUp() if you want a fast-fail check before running queries.
     */
    protected function assertTenantSchemaReady(array $requiredTables = []): void
    {
        $defaults = ['chart_of_accounts', 'journal_entries', 'journal_entry_lines', 'vendors', 'invoices'];
        $tables = !empty($requiredTables) ? $requiredTables : $defaults;

        foreach ($tables as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "Required tenant table '{$table}' does not exist. Tenant migrations may have failed."
            );
        }
    }

    /**
     * Bypass tenant middleware that requires central DB context in tests.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            \App\Http\Middleware\SetTenantFromUserPreference::class,
            \App\Http\Middleware\EnsureTenantSearchPath::class,
        ]);
    }

    protected function bindTenantContext(string $tenantId = 'test-tenant', array $attributes = []): Tenant
    {
        $tenant = new Tenant(array_merge([
            'id' => $tenantId,
            'name' => 'Test Tenant',
        ], $attributes));

        $this->boundTestTenant = $tenant;

        app()->instance(TenantContract::class, $tenant);

        $tenancy = app(Tenancy::class);
        $tenancy->tenant = $tenant;
        $tenancy->initialized = true;

        return $tenant;
    }

    protected function tenantScopedPath(string $path): string
    {
        if (!$this->boundTestTenant) {
            throw new \RuntimeException('No test tenant context has been bound.');
        }

        return 'reports/' . $this->boundTestTenant->getTenantKey() . '/' . ltrim($path, '/');
    }

    protected function tearDown(): void
    {
        if (app()->bound(TenantContract::class)) {
            app()->forgetInstance(TenantContract::class);
        }

        if (app()->bound(Tenancy::class)) {
            app(Tenancy::class)->end();
        }

        $this->boundTestTenant = null;

        parent::tearDown();
    }
}
