<?php

namespace Modules\PaymentGateway\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\PaymentGateway\Console\Commands\BackfillWebhookTenantLookups;
use Modules\PaymentGateway\Console\Commands\FixStuckWebOrders;

class PaymentGatewayServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register custom autoloader for PaymentGateway module
        spl_autoload_register(function ($class) {
            // Only handle Modules\PaymentGateway namespace
            if (strpos($class, 'Modules\\PaymentGateway\\') !== 0) {
                return;
            }
            
            // Convert namespace to file path
            $classPath = str_replace('Modules\\PaymentGateway\\', '', $class);
            $classPath = str_replace('\\', '/', $classPath);
            $file = __DIR__ . '/../../src/' . $classPath . '.php';
            
            // Also check Console directory for commands
            if (!file_exists($file)) {
                $file = __DIR__ . '/../../Console/' . $classPath . '.php';
            }
            
            if (file_exists($file)) {
                require_once $file;
            }
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Tenant migrations are in database/migrations/tenant/ and discovered
        // automatically via ModuleManager::tenantMigrationPaths().
        // Do NOT also loadMigrationsFrom here or they will run during central migrations.

        // Routes are loaded via ModuleManager::tenantApiRouteFiles() in tenant.php
        // Do NOT also loadRoutesFrom here or routes will be registered twice,
        // causing "Another route has already been assigned name" errors.
        
        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                BackfillWebhookTenantLookups::class,
                FixStuckWebOrders::class,
            ]);
        }
    }
}
