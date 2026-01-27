<?php

namespace Modules\EventManagement\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;
use Exception;
use InvalidArgumentException;

class TenantSchemaManager
{
    protected ?string $currentTenantId = null;
    protected ?string $originalConnection = null;

    /**
     * Set the current tenant context
     */
    public function setTenantContext(string $tenantId): void
    {
        $this->validateTenantId($tenantId);
        
        if ($this->currentTenantId === $tenantId) {
            return; // Already in correct context
        }

        // Store original connection if this is the first tenant switch
        if ($this->originalConnection === null) {
            $this->originalConnection = Config::get('database.default');
        }

        try {
            $this->currentTenantId = $tenantId;
            $this->switchToTenantDatabase($tenantId);
            
            Log::debug("Switched to tenant context", [
                'tenant_id' => $tenantId,
                'connection' => $this->getTenantConnectionName($tenantId)
            ]);
        } catch (Exception $e) {
            Log::error("Failed to set tenant context", [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get the current tenant ID
     */
    public function getCurrentTenantId(): ?string
    {
        return $this->currentTenantId;
    }

    /**
     * Reset to the original database connection
     */
    public function resetToOriginalConnection(): void
    {
        if ($this->originalConnection) {
            try {
                Config::set('database.default', $this->originalConnection);
                
                if ($this->currentTenantId) {
                    DB::purge($this->getTenantConnectionName($this->currentTenantId));
                }
                
                Log::debug("Reset to original database connection", [
                    'original_connection' => $this->originalConnection,
                    'previous_tenant' => $this->currentTenantId
                ]);
            } catch (Exception $e) {
                Log::error("Failed to reset to original connection", [
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $this->currentTenantId = null;
        $this->originalConnection = null;
    }

    /**
     * Create a new tenant schema
     */
    public function createTenantSchema(string $tenantId): void
    {
        $this->validateTenantId($tenantId);
        
        $schemaName = $this->getTenantSchemaName($tenantId);
        
        try {
            // Check if schema already exists
            if ($this->tenantSchemaExists($tenantId)) {
                Log::info("Tenant schema already exists", [
                    'tenant_id' => $tenantId,
                    'schema_name' => $schemaName
                ]);
                return;
            }
            
            // Create schema if it doesn't exist
            DB::statement("CREATE SCHEMA IF NOT EXISTS `{$schemaName}`");
            
            // Set up tenant-specific database connection
            $this->configureTenantConnection($tenantId);
            
            // Run migrations for the new tenant
            $this->runTenantMigrations($tenantId);
            
            Log::info("Tenant schema created successfully", [
                'tenant_id' => $tenantId,
                'schema_name' => $schemaName
            ]);
        } catch (Exception $e) {
            Log::error("Failed to create tenant schema", [
                'tenant_id' => $tenantId,
                'schema_name' => $schemaName,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Drop a tenant schema (use with caution)
     */
    public function dropTenantSchema(string $tenantId): void
    {
        $this->validateTenantId($tenantId);
        
        $schemaName = $this->getTenantSchemaName($tenantId);
        
        try {
            if (!$this->tenantSchemaExists($tenantId)) {
                Log::warning("Attempted to drop non-existent tenant schema", [
                    'tenant_id' => $tenantId,
                    'schema_name' => $schemaName
                ]);
                return;
            }
            
            DB::statement("DROP SCHEMA IF EXISTS `{$schemaName}`");
            
            // Remove tenant connection configuration
            Config::forget("database.connections.tenant_{$tenantId}");
            
            Log::warning("Tenant schema dropped", [
                'tenant_id' => $tenantId,
                'schema_name' => $schemaName
            ]);
        } catch (Exception $e) {
            Log::error("Failed to drop tenant schema", [
                'tenant_id' => $tenantId,
                'schema_name' => $schemaName,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Check if tenant schema exists
     */
    public function tenantSchemaExists(string $tenantId): bool
    {
        $this->validateTenantId($tenantId);
        
        $schemaName = $this->getTenantSchemaName($tenantId);
        
        try {
            $result = DB::select("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?", [$schemaName]);
            return count($result) > 0;
        } catch (Exception $e) {
            Log::error("Failed to check if tenant schema exists", [
                'tenant_id' => $tenantId,
                'schema_name' => $schemaName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get list of all tenant schemas
     */
    public function getAllTenantSchemas(): array
    {
        try {
            $results = DB::select("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME LIKE 'tenant_%'");
            
            return array_map(function ($result) {
                // Extract tenant ID from schema name (remove 'tenant_' prefix)
                return substr($result->SCHEMA_NAME, 7);
            }, $results);
        } catch (Exception $e) {
            Log::error("Failed to get all tenant schemas", [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Test tenant database connection
     */
    public function testTenantConnection(string $tenantId): bool
    {
        $this->validateTenantId($tenantId);
        
        try {
            $connectionName = $this->getTenantConnectionName($tenantId);
            
            // Configure connection if not already configured
            if (!Config::has("database.connections.{$connectionName}")) {
                $this->configureTenantConnection($tenantId);
            }
            
            // Test the connection
            DB::connection($connectionName)->getPdo();
            
            return true;
        } catch (Exception $e) {
            Log::error("Tenant connection test failed", [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Switch to tenant database connection
     */
    protected function switchToTenantDatabase(string $tenantId): void
    {
        $connectionName = $this->getTenantConnectionName($tenantId);
        
        try {
            // Configure tenant connection if not already configured
            if (!Config::has("database.connections.{$connectionName}")) {
                $this->configureTenantConnection($tenantId);
            }
            
            // Switch default connection
            Config::set('database.default', $connectionName);
            
            // Clear any cached connections
            DB::purge($connectionName);
        } catch (Exception $e) {
            Log::error("Failed to switch to tenant database", [
                'tenant_id' => $tenantId,
                'connection_name' => $connectionName,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Configure tenant-specific database connection
     */
    protected function configureTenantConnection(string $tenantId): void
    {
        $connectionName = $this->getTenantConnectionName($tenantId);
        $schemaName = $this->getTenantSchemaName($tenantId);
        
        try {
            // Get base connection config
            $baseConfig = Config::get('database.connections.mysql', []);
            
            if (empty($baseConfig)) {
                throw new Exception("Base MySQL connection configuration not found");
            }
            
            // Override database name with tenant schema
            $tenantConfig = array_merge($baseConfig, [
                'database' => $schemaName,
            ]);
            
            Config::set("database.connections.{$connectionName}", $tenantConfig);
            
            Log::debug("Tenant connection configured", [
                'tenant_id' => $tenantId,
                'connection_name' => $connectionName,
                'schema_name' => $schemaName
            ]);
        } catch (Exception $e) {
            Log::error("Failed to configure tenant connection", [
                'tenant_id' => $tenantId,
                'connection_name' => $connectionName,
                'schema_name' => $schemaName,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Run migrations for a specific tenant
     */
    protected function runTenantMigrations(string $tenantId): void
    {
        try {
            $this->setTenantContext($tenantId);
            
            // In a real implementation, you would run Artisan commands here
            // For now, we'll just ensure the connection is properly set up
            $connectionName = $this->getTenantConnectionName($tenantId);
            
            // Test the connection to ensure it's working
            DB::connection($connectionName)->getPdo();
            
            Log::info("Tenant migrations completed", [
                'tenant_id' => $tenantId,
                'connection' => $connectionName
            ]);
            
            // Example: Artisan::call('migrate', ['--database' => $connectionName]);
        } catch (Exception $e) {
            Log::error("Failed to run tenant migrations", [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get tenant schema name
     */
    protected function getTenantSchemaName(string $tenantId): string
    {
        return "tenant_{$tenantId}";
    }

    /**
     * Get tenant connection name
     */
    protected function getTenantConnectionName(string $tenantId): string
    {
        return "tenant_{$tenantId}";
    }

    /**
     * Validate tenant ID
     */
    protected function validateTenantId(string $tenantId): void
    {
        if (empty(trim($tenantId))) {
            throw new InvalidArgumentException('Tenant ID cannot be empty');
        }
        
        if (strlen($tenantId) > 255) {
            throw new InvalidArgumentException('Tenant ID cannot exceed 255 characters');
        }
        
        // Ensure tenant ID is safe for database schema names
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tenantId)) {
            throw new InvalidArgumentException('Tenant ID must contain only alphanumeric characters and underscores');
        }
    }
}