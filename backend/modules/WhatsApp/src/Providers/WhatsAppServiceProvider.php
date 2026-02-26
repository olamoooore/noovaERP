<?php

namespace Modules\WhatsApp\Providers;

use Illuminate\Support\ServiceProvider;

class WhatsAppServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register custom autoloader for WhatsApp module
        spl_autoload_register(function ($class) {
            // Only handle Modules\WhatsApp namespace
            if (strpos($class, 'Modules\\WhatsApp\\') !== 0) {
                return;
            }
            
            // Convert namespace to file path
            $classPath = str_replace('Modules\\WhatsApp\\', '', $class);
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
        if (function_exists('tenancy') && tenancy()->initialized) {
            $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        }

        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/../../routes/api.php');
    }
}
