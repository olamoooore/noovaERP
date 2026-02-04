<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TenantDatabaseServiceProvider extends ServiceProvider
{
    /**
     * Track which connections have been initialized to avoid infinite loops
     */
    private static $initializedConnections = [];
    
    /**
     * Flag to prevent recursive calls
     */
    private static $isSettingSearchPath = false;

    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Listen to all database queries
        DB::listen(function ($query) {
            // Prevent infinite recursion
            if (self::$isSettingSearchPath) {
                return;
            }
            
            // Skip if this is a SET search_path query itself
            if (stripos($query->sql, 'SET search_path') !== false) {
                return;
            }
            
            $connection = DB::connection($query->connectionName);
            $connectionId = spl_object_id($connection->getPdo());
            
            // Only initialize each connection once
            if (!in_array($connectionId, self::$initializedConnections)) {
                $searchPath = config("database.connections.{$query->connectionName}.search_path");
                
                // Only set if search_path is configured and not the default 'public'
                if ($searchPath && $searchPath !== 'public') {
                    try {
                        self::$isSettingSearchPath = true;
                        $connection->statement("SET search_path TO {$searchPath}");
                        self::$initializedConnections[] = $connectionId;
                        
                        Log::debug("TenantDatabaseServiceProvider: Set search_path on new connection", [
                            'connection_id' => $connectionId,
                            'connection_name' => $query->connectionName,
                            'search_path' => $searchPath
                        ]);
                    } catch (\Exception $e) {
                        Log::error("TenantDatabaseServiceProvider: Failed to set search_path", [
                            'error' => $e->getMessage(),
                            'search_path' => $searchPath
                        ]);
                    } finally {
                        self::$isSettingSearchPath = false;
                    }
                }
            }
        });
    }
}
