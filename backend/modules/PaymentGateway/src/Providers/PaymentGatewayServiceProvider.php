<?php

namespace Modules\PaymentGateway\Providers;

use Illuminate\Support\ServiceProvider;
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
        // Only load migrations when tenancy is initialized
        // This prevents tenant-specific migrations from running during central migrations
        if (tenancy()->initialized) {
            $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        }

        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/../../routes/api.php');
        
        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                FixStuckWebOrders::class,
            ]);
        }
    }
}
