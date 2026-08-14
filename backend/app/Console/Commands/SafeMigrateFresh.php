<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\InputOption;

class SafeMigrateFresh extends Command
{
    protected $signature = 'migrate:fresh-safe
                            {--seed : Seed the database after fresh migration}
                            {--force : Skip confirmation prompt}
                            {--backup : Create a backup before wiping}';

    protected $description = 'Safely run migrate:fresh with tenant awareness and backup protection';

    public function handle(): int
    {
        // ── 1. Check for tenant schemas ──────────────────────────────────────
        $tenantSchemas = $this->getTenantSchemas();
        $tenantCount = count($tenantSchemas);

        // ── 2. Check for data in tenant schemas ──────────────────────────────
        $tablesWithData = $this->checkTenantData($tenantSchemas);

        // ── 3. Show warning ──────────────────────────────────────────────────
        $this->newLine();
        $this->error('╔══════════════════════════════════════════════════════════════╗');
        $this->error('║              ⚠️  DANGER: DATABASE WIPE DETECTED              ║');
        $this->error('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();
        $this->warn("This command will DROP ALL TABLES in the public schema.");
        $this->warn("Tenant schemas ({$tenantCount} found) will NOT be dropped.");
        $this->newLine();

        if ($tenantCount > 0) {
            $this->info("Found {$tenantCount} tenant schema(s):");
            foreach (array_slice($tenantSchemas, 0, 5) as $schema) {
                $tableCount = $tablesWithData[$schema] ?? 0;
                $this->line("  - {$schema} ({$tableCount} tables with data)");
            }
            if ($tenantCount > 5) {
                $this->line("  ... and " . ($tenantCount - 5) . " more");
            }
            $this->newLine();
        }

        $this->warn("This will DELETE:");
        $this->warn("  - All users, tenants, subscriptions, entitlements");
        $this->warn("  - All catalog items, plan families, permissions");
        $this->warn("  - All payment gateways, communication settings");
        $this->warn("  - ALL DATA in public schema tables");
        $this->newLine();
        $this->warn("This will NOT delete:");
        $this->warn("  - Tenant schema data (invoices, orders, contacts, etc.)");
        $this->newLine();

        // ── 4. Backup option ─────────────────────────────────────────────────
        if ($this->option('backup')) {
            $this->createBackup();
        }

        // ── 5. Confirmation ──────────────────────────────────────────────────
        if (!$this->option('force')) {
            $this->warn("⚠️  THIS ACTION IS IRREVERSIBLE for public schema data!");
            $this->newLine();

            $confirm = $this->confirm('Are you absolutely sure you want to WIPE the public schema?', false);
            if (!$confirm) {
                $this->info('Migration cancelled. Your data is safe.');
                return Command::SUCCESS;
            }

            // Double confirmation for safety
            $doubleConfirm = $this->confirm('Type "yes" to confirm: Drop ALL public schema tables?', false);
            if (!$doubleConfirm) {
                $this->info('Migration cancelled. Your data is safe.');
                return Command::SUCCESS;
            }
        }

        // ── 6. Run migrate:fresh ─────────────────────────────────────────────
        $this->newLine();
        $this->info('Running migrate:fresh...');

        $params = ['--force' => true];
        if ($this->option('seed')) {
            $params['--seed'] = true;
        }

        Artisan::call('migrate:fresh', $params, $this->output);

        $this->newLine();
        $this->error('╔══════════════════════════════════════════════════════════════╗');
        $this->error('║  ⚠️  PUBLIC SCHEMA HAS BEEN WIPED!                          ║');
        $this->error('║                                                              ║');
        $this->error('║  You MUST now:                                               ║');
        $this->error('║  1. Run the DemoTenantSeeder to restore demo data            ║');
        $this->error('║  2. Rebuild entitlements for all tenants                     ║');
        $this->error('║  3. Verify payment gateways and settings                     ║');
        $this->error('║                                                              ║');
        $this->error('║  Run: php artisan db:seed --class=DemoTenantSeeder           ║');
        $this->error('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();

        return Command::SUCCESS;
    }

    private function getTenantSchemas(): array
    {
        $result = DB::select(
            "SELECT schema_name FROM information_schema.schemata 
             WHERE schema_name NOT IN ('public', 'information_schema', 'pg_catalog', 'pg_toast')"
        );

        return array_map(fn($row) => $row->schema_name, $result);
    }

    private function checkTenantData(array $schemas): array
    {
        $tablesWithData = [];

        foreach ($schemas as $schema) {
            try {
                $result = DB::select(
                    "SELECT COUNT(*) as count FROM information_schema.tables 
                     WHERE table_schema = ? AND table_type = 'BASE TABLE'",
                    [$schema]
                );
                $tablesWithData[$schema] = $result[0]->count ?? 0;
            } catch (\Throwable $e) {
                $tablesWithData[$schema] = 0;
            }
        }

        return $tablesWithData;
    }

    private function createBackup(): void
    {
        $this->info('Creating backup before wipe...');

        $backupDir = database_path('backups');
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $timestamp = date('Y-m-d_H-i-s');
        $backupFile = "{$backupDir}/pre_wipe_{$timestamp}.sql";

        $dbConfig = config('database.connections.pgsql');
        $host = $dbConfig['host'] ?? '127.0.0.1';
        $port = $dbConfig['port'] ?? '5432';
        $database = $dbConfig['database'];
        $username = $dbConfig['username'] ?? 'mac';

        $command = sprintf(
            'pg_dump -h %s -p %s -U %s -d %s --schema=public -f %s 2>&1',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($database),
            escapeshellarg($backupFile)
        );

        exec($command, $output, $returnCode);

        if ($returnCode === 0 && file_exists($backupFile)) {
            $size = number_format(filesize($backupFile) / 1024, 1);
            $this->info("Backup saved: {$backupFile} ({$size} KB)");
        } else {
            $this->warn("Backup failed or pg_dump not available. Proceeding without backup.");
        }
    }
}
