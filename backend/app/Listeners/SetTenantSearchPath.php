<?php

namespace App\Listeners;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stancl\Tenancy\Events\TenancyInitialized;

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
        
        // Update the config so new connections will use this search_path
        config(["database.connections.{$connectionName}.search_path" => "\"{$schemaName}\", public"]);
        
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
        DB::statement("SET search_path TO \"{$schemaName}\", public");
        
        // Register a macro to ensure search_path is set before every query
        // This is a fallback in case a new connection is created
        if (!DB::hasMacro('ensureSearchPath')) {
            DB::macro('ensureSearchPath', function () use ($schemaName) {
                static $checked = [];
                $connectionId = spl_object_id(DB::connection()->getPdo());
                
                if (!in_array($connectionId, $checked)) {
                    DB::statement("SET search_path TO \"{$schemaName}\", public");
                    $checked[] = $connectionId;
                    Log::debug("SetTenantSearchPath: Ensured search_path on connection", [
                        'connection_id' => $connectionId,
                        'schema' => $schemaName
                    ]);
                }
            });
        }
        
        // Verify
        $result = DB::select("SHOW search_path");
        $currentPath = $result[0]->search_path ?? 'unknown';
        Log::info("SetTenantSearchPath: Current search_path is {$currentPath}");
        
        // Test query to verify data is accessible
        $testCount = DB::table('journal_entries')->count();
        Log::info("SetTenantSearchPath: Found {$testCount} journal entries in current search_path");
    }
}
