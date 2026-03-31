<?php

namespace App\Listeners;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stancl\Tenancy\Events\TenancyInitialized;
use App\Models\Setting;

class SetTenantSearchPath
{
    public function handle(TenancyInitialized $event): void
    {
        $tenant = $event->tenancy->tenant;
        
        // Use getSchemaName() to get the friendly schema name (e.g., 'ramoose', 'jingxi')
        // instead of getTenantKey() which returns the UUID
        $schemaName = $tenant->getSchemaName();
        
        Log::info("SetTenantSearchPath: Setting search_path to {$schemaName}", [
            'tenant_id' => $tenant->id,
            'tenant_name' => $tenant->name ?? 'unknown'
        ]);
        
        // Get the default connection name
        $connectionName = DB::getDefaultConnection();
        $searchPath = $this->resolveSearchPath($schemaName);
        
        // Update the config so new connections will use this search_path
        config(["database.connections.{$connectionName}.search_path" => $searchPath]);
        
        // CRITICAL: Set the PDO init command to run SET search_path on EVERY new connection
        // This ensures that even if Laravel creates a new connection, it will have the correct search_path
        config(["database.connections.{$connectionName}.options" => [
            \PDO::ATTR_PERSISTENT => false,
            \PDO::ATTR_EMULATE_PREPARES => true,
        ]]);
        
        // Purge existing connections to force reconnection with new config
        DB::purge($connectionName);
        
        // Reconnect with the new search_path
        DB::reconnect($connectionName);
        
        // Set search_path explicitly on the current connection
        DB::statement("SET search_path TO {$searchPath}");
        
        // Per-request enforcement is handled by EnsureTenantSearchPath middleware.
        // No macro fallback needed — that pattern leaked closures and duplicated
        // the middleware's responsibility.
        
        // Verify
        $result = DB::select("SHOW search_path");
        $currentPath = $result[0]->search_path ?? 'unknown';
        Log::info("SetTenantSearchPath: Current search_path is {$currentPath}");

        if ($this->isTenantMigrationCommand()) {
            return;
        }
        
        // Test query to verify data is accessible
        $testCount = DB::table('journal_entries')->count();
        Log::info("SetTenantSearchPath: Found {$testCount} journal entries in current search_path");

        // Reload mail configuration from tenant settings
        // This is critical for queued jobs that send emails
        $this->loadTenantMailConfig();
    }

    protected function resolveSearchPath(string $schemaName): string
    {
        $quotedSchema = "\"{$schemaName}\"";

        if (!$this->isTenantMigrationCommand()) {
            return "{$quotedSchema}, public";
        }

        return $quotedSchema;
    }

    protected function isTenantMigrationCommand(): bool
    {
        if (!app()->runningInConsole()) {
            return false;
        }

        $argv = $_SERVER['argv'] ?? [];
        $command = implode(' ', $argv);

        return str_contains($command, 'tenants:migrate');
    }

    /**
     * Load mail configuration from tenant settings after tenancy is initialized.
     * This is critical for queued jobs that send emails.
     */
    protected function loadTenantMailConfig(): void
    {
        try {
            $mail = Setting::getValue('mail_config');

            if ($mail && is_array($mail)) {
                $config = [
                    'transport' => $mail['driver'] ?? 'smtp',
                    'host' => $mail['host'] ?? config('mail.mailers.smtp.host'),
                    'port' => $mail['port'] ?? config('mail.mailers.smtp.port'),
                    'encryption' => $mail['encryption'] ?? config('mail.mailers.smtp.encryption'),
                    'username' => $mail['username'] ?? config('mail.mailers.smtp.username'),
                    'password' => $mail['password'] ?? config('mail.mailers.smtp.password'),
                    'timeout' => null,
                    'local_domain' => env('MAIL_EHLO_DOMAIN'),
                ];

                // Update SMTP config
                config(['mail.mailers.smtp' => $config]);

                // Update From Address
                if (!empty($mail['from_address'])) {
                    config(['mail.from.address' => $mail['from_address']]);
                }
                if (!empty($mail['from_name'])) {
                    config(['mail.from.name' => $mail['from_name']]);
                }

                Log::info("SetTenantSearchPath: Loaded tenant mail config", [
                    'host' => $config['host'] ?? 'not set',
                    'port' => $config['port'] ?? 'not set',
                    'from_address' => $mail['from_address'] ?? 'not set',
                ]);
            }
        } catch (\Exception $e) {
            Log::warning("SetTenantSearchPath: Failed to load tenant mail config", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
