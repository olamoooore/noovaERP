<?php

namespace Tests;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
    protected function refreshTestDatabase()
    {
        if (! RefreshDatabaseState::$migrated || ! $this->tenantSchemaExists()) {
            $this->runTenantMigrations();
            RefreshDatabaseState::$migrated = true;
        }

        $this->beginDatabaseTransaction();
    }

    protected function tenantSchemaExists(): bool
    {
        return Schema::hasTable('invoices')
            && Schema::hasTable('journal_entries')
            && Schema::hasTable('document_correction_links');
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
        // Purge the test database by dropping it and recreating it, then
        // immediately resetting the connection. This is the only atomic way
        // to reliably clear a PostgreSQL database regardless of its prior
        // state.
        if (config('database.default') === 'pgsql') {
            $dbName = config('database.connections.pgsql.database');
            DB::disconnect('pgsql');
            $pdo = new \PDO(
                'pgsql:host='.config('database.connections.pgsql.host').';port='.config('database.connections.pgsql.port').';dbname=postgres',
                config('database.connections.pgsql.username'),
                config('database.connections.pgsql.password'),
            );

            // DROP … WITH (FORCE) (PostgreSQL 13+) terminates lingering
            // sessions itself, so no pre-emptive pg_terminate_backend sweep
            // is needed here. A sweep matching `datname IS NULL` also kills
            // PostgreSQL background workers (checkpointer, WAL writer,
            // autovacuum launcher, …) which destabilises the cluster and
            // manifests as 'no connection to the server' / dropped-database
            // races several test classes later in long PHPUnit runs.
            try {
                $pdo->exec("DROP DATABASE IF EXISTS \"{$dbName}\" WITH (FORCE)");
            } catch (\PDOException $e) {
                // PostgreSQL < 13: WITH (FORCE) unsupported. Terminate only
                // sessions of THIS database, then retry a plain drop.
                $pdo->exec(
                    'SELECT pg_terminate_backend(pid) FROM pg_stat_activity '.
                    'WHERE datname = '.$pdo->quote($dbName).' AND pid <> pg_backend_pid()'
                );
                $pdo->exec("DROP DATABASE IF EXISTS \"{$dbName}\"");
            }
            $pdo->exec("CREATE DATABASE \"{$dbName}\"");
            $pdo = null;
            DB::purge('pgsql');
            DB::reconnect('pgsql');
            DB::statement('SET search_path TO public');
        } else {
            Artisan::call('db:wipe', ['--force' => true]);
        }

        // Gather central-only migration files (exclude those also in tenant/).
        $tenantFileNames = is_dir(database_path('migrations/tenant'))
            ? array_map('basename', glob(database_path('migrations/tenant').'/*.php'))
            : [];

        $centralFiles = array_values(array_filter(
            glob(database_path('migrations').'/*.php'),
            fn (string $f) => ! in_array(basename($f), $tenantFileNames, true),
        ));

        // Copy central-only files into a temp directory so a single
        // `migrate --path <dir>` call picks them all up without
        // accidentally scanning the parent directory.
        $tmpDir = sys_get_temp_dir().'/noovaerp_ctrl_'.getmypid();
        @mkdir($tmpDir, 0755, true);
        try {
            foreach ($centralFiles as $file) {
                $dest = $tmpDir.'/'.basename($file);
                file_put_contents($dest, file_get_contents($file));
            }

            Artisan::call('migrate', [
                '--path' => $tmpDir,
                '--realpath' => true,
                '--force' => true,
            ]);
        } finally {
            $tmpFiles = glob($tmpDir.'/*') ?: [];
            foreach ($tmpFiles as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }

        // Communication module central migrations.
        $commDir = base_path('modules/Communication/database/migrations');
        if (is_dir($commDir)) {
            Artisan::call('migrate', [
                '--path' => $commDir,
                '--realpath' => true,
                '--force' => true,
            ]);
        }

        // Run all tenant migration paths in a SINGLE `migrate` invocation
        // so Laravel manages the connection lifecycle once. Splitting into
        // per-path Artisan::call() invocations can cause Schema::hasTable()
        // to return stale results across calls (observed: fulfillment_providers
        // exists after one path but a later path's CREATE statement fails
        // with 'relation already exists').
        $existingTenantPaths = array_values(array_filter(
            array_map(fn (string $p) => base_path($p), $this->getTenantMigrationPaths()),
            fn (string $p) => is_dir($p),
        ));

        if (! empty($existingTenantPaths)) {
            Artisan::call('migrate', [
                '--path' => $existingTenantPaths,
                '--realpath' => true,
                '--force' => true,
            ]);
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
            // Contact module must run first — core tenant migrations
            // (invoices, etc.) reference core_contacts via foreign keys.
            'modules/Contact/database/migrations/tenant',
            'database/migrations/tenant',
            'modules/EventManagement/Database/Migrations/tenant',
            'modules/FinancialReporting/database/migrations/tenant',
            'modules/HRM/database/migrations/tenant',
            'modules/CRM/database/migrations/tenant',
            'modules/OCRBookkeeping/database/migrations/tenant',
            'modules/POS/database/migrations/tenant',
            'modules/Ecommerce/database/migrations/tenant',
            'modules/AI/database/migrations/tenant',
            'modules/FieldService/database/migrations/tenant',
            'modules/WebsiteBuilder/database/migrations/tenant',
            'modules/IoT/database/migrations/tenant',
            'modules/Loyalty/database/migrations/tenant',
            'modules/TemplateBuilder/database/migrations/tenant',
            'modules/PaymentGateway/database/migrations/tenant',
            'modules/Fulfillment/database/migrations/tenant',
            'modules/WhatsApp/database/migrations/tenant',
            'modules/Storefront/database/migrations/tenant',
        ];
    }

    /**
     * Verify that critical tenant tables exist.
     * Call this in setUp() if you want a fast-fail check before running queries.
     */
    protected function assertTenantSchemaReady(array $requiredTables = []): void
    {
        $defaults = ['chart_of_accounts', 'journal_entries', 'journal_entry_lines', 'vendors', 'invoices'];
        $tables = ! empty($requiredTables) ? $requiredTables : $defaults;

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
            \App\Http\Middleware\SyncTenantUserFromCentralAuth::class,
            // Disable platform rate limiting (120 req/min) so heavy test
            // suites that fire many requests in a single second don't
            // receive 429 responses.
            \App\Http\Middleware\PlatformRateLimitMiddleware::class,
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

    /**
     * Create a tenant-scoped Admin API user: public.tenants row, central user,
     * user_company_access (role=admin) and the tenant Spatie Admin role with
     * the seeded permission matrix. Returns the user.
     *
     * Required for endpoints guarded by RequirePermission (e.g. every
     * FinancialReporting route since issue #103) — without the central
     * user_company_access role, TenantRoleService resolves no Spatie role and
     * the middleware answers 403.
     */
    protected function setUpTenantAdminUser(string $tenantName = 'Test Tenant'): \App\Models\User
    {
        $tenantId = (string) \Illuminate\Support\Str::uuid();

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $tenantId],
            [
                'name' => $tenantName,
                'email' => 'tenant-'.substr(md5($tenantId), 0, 8).'@example.com',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $user = \App\Models\User::factory()->create();

        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            [
                'name' => $user->name,
                'password' => $user->password,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');

        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $tenantId],
            [
                'role' => 'admin',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->bindTenantContextWithPermissions($tenantId, $user);

        return $user;
    }

    /**
     * Bind a tenant context AND seed the Spatie role + permission matrix
     * (RolePermissionSeeder) inside that tenant schema, so the test user
     * can exercise the authority gate framework (ADR-005 §8) end-to-end.
     *
     * Required by tests that call services which route through
     * PostingEngine::post() and trigger PermissionGate::evaluate() —
     * introduced at SHA 91d50469 when PostingEngine started rejecting
     * missing tenant context with MISSING_TENANT_CONTEXT.
     *
     * If $user is provided, the user is assigned the Spatie role
     * (which carries accounting.write, accounting.post, accounting.reverse,
     * and accounting.lock per RolePermissionSeeder::PERMISSION_MATRIX).
     * Default Spatie role name is 'Admin' — UserRole::ADMIN->spatieName()
     * returns 'Admin' (Title Case), not 'admin'.
     *
     * If $autoAssignRole is true (default), any User model created during the
     * test (e.g. by Invoice::factory()->create()) is automatically assigned
     * the Spatie role. This covers tests where the user is created deep in a
     * service call rather than explicitly in setUp().
     *
     * The seeder is idempotent — safe to call repeatedly.
     */
    protected function bindTenantContextWithPermissions(
        string $tenantId = 'test-tenant',
        ?\App\Models\User $user = null,
        string $spatieRoleName = 'Admin',
        bool $autoAssignRole = true,
        array $attributes = []
    ): Tenant {
        $tenant = $this->bindTenantContext($tenantId, $attributes);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        if ($user && method_exists($user, 'assignRole')) {
            $user->assignRole($spatieRoleName);
        }

        if ($autoAssignRole) {
            // Use a one-shot model event listener so any User created during
            // this test is automatically assigned the Spatie role. The listener
            // is cleared in tearDown to avoid leaking into other tests.
            \App\Models\User::created(function (\App\Models\User $createdUser) use ($spatieRoleName) {
                if (method_exists($createdUser, 'assignRole')) {
                    try {
                        $createdUser->assignRole($spatieRoleName);
                    } catch (\Throwable $e) {
                        // Spatie role may not exist yet (e.g. listener fires
                        // before the seeder commits). Ignore — caller can
                        // assign explicitly.
                    }
                }
            });
        }

        return $tenant;
    }

    protected function tenantScopedPath(string $path): string
    {
        if (! $this->boundTestTenant) {
            throw new \RuntimeException('No test tenant context has been bound.');
        }

        return 'reports/'.$this->boundTestTenant->getTenantKey().'/'.ltrim($path, '/');
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
