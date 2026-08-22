<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Provision a test database with BOTH central AND tenant migrations.
 *
 * This mirrors what Tests\TenantTestCase::runTenantMigrations() does, but
 * exposed as a standalone artisan command so CI matrix buckets can pre-seed
 * the schema before running their first test. Once the tenant tables exist
 * in the test database, TenantTestCase::refreshTestDatabase() detects them
 * via tenantSchemaExists() and skips its own (slow) lazy migration step,
 * which is critical for parallel matrix buckets where each job has its own
 * postgres service.
 *
 * Usage:
 *   php artisan migrate:tenant-test-schema
 *   php artisan migrate:tenant-test-schema --connection=pgsql
 */
class MigrateTenantTestSchema extends Command
{
    protected $signature = 'migrate:tenant-test-schema
                            {--connection=pgsql : Database connection to provision}';

    protected $description = 'Provision test database with central + tenant migrations (used by CI matrix buckets).';

    /**
     * Central-only migration paths (tenant/ subdirectory is excluded).
     * Mirrors Tests\TenantTestCase::runTenantMigrations().
     */
    private function centralOnlyPaths(): array
    {
        return [
            database_path('migrations'),
            base_path('modules/Communication/database/migrations'),
        ];
    }

    /**
     * Tenant migration paths in dependency order.
     * Contact must run first (core tenant migrations FK into core_contacts).
     */
    private function tenantPaths(): array
    {
        return [
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
        ];
    }

    public function handle(): int
    {
        $connection = $this->option('connection');
        $config = config("database.connections.{$connection}");

        if (! $config || ($config['driver'] ?? null) !== 'pgsql') {
            $this->error("Connection '{$connection}' is not a pgsql connection. Aborting.");
            return self::FAILURE;
        }

        $dbName = $config['database'];
        $this->info("Provisioning test database '{$dbName}' (driver: pgsql) with central + tenant migrations");

        // Step 1: Atomic drop & recreate of the test database.
        // This guarantees a clean slate regardless of prior state.
        $this->step('Dropping and recreating database');
        DB::disconnect($connection);
        $pdo = new \PDO(
            "pgsql:host={$config['host']};port={$config['port']};dbname=postgres",
            $config['username'],
            $config['password'],
        );
        $pdo->exec("DROP DATABASE IF EXISTS \"{$dbName}\"");
        $pdo->exec("CREATE DATABASE \"{$dbName}\"");
        $pdo = null;
        DB::purge($connection);
        DB::reconnect($connection);
        DB::statement('SET search_path TO public');

        // Step 2: Central-only migrations (exclude tenant/ subdirectory).
        // We copy central-only files into a temp dir so a single
        // `migrate --path <dir>` call picks them up without accidentally
        // scanning the tenant/ subdirectory.
        $this->step('Running central-only migrations');
        $centralDir = database_path('migrations');
        $tenantFileNames = is_dir(database_path('migrations/tenant'))
            ? array_map('basename', glob(database_path('migrations/tenant').'/*.php'))
            : [];

        $centralFiles = array_values(array_filter(
            glob($centralDir.'/*.php') ?: [],
            fn (string $f) => ! in_array(basename($f), $tenantFileNames, true),
        ));

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
            foreach ((glob($tmpDir.'/*') ?: []) as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }

        // Step 3: Communication module central migrations.
        $commDir = base_path('modules/Communication/database/migrations');
        if (is_dir($commDir)) {
            $this->step('Running Communication central migrations');
            Artisan::call('migrate', [
                '--path' => $commDir,
                '--realpath' => true,
                '--force' => true,
            ]);
        }

        // Step 4: Tenant migrations in dependency order.
        foreach ($this->tenantPaths() as $path) {
            $realPath = base_path($path);
            if (! is_dir($realPath)) {
                continue;
            }

            $this->step("Running tenant migrations: {$path}");
            Artisan::call('migrate', [
                '--path' => [$realPath],
                '--realpath' => true,
                '--force' => true,
            ]);
        }

        $this->info('Test database provisioning complete.');
        $this->table(
            ['Table', 'Exists'],
            collect(DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename"))
                ->map(fn ($r) => [(string) $r->tablename, 'yes'])
                ->take(20)
                ->all(),
        );

        return self::SUCCESS;
    }

    private function step(string $message): void
    {
        $this->line("→ {$message}");
    }
}
