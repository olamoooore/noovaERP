<?php

namespace Modules\IoT\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\IoT\Services\AgentHttpClient;
use Modules\IoT\Services\HttpDeviceClient;
use Modules\IoT\Services\MqttDeviceClient;
use Modules\IoT\Services\WebhookDeviceClient;
use Modules\IoT\Services\DeviceCommandRouter;

class IoTServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register custom autoloader for IoT module
        spl_autoload_register(function ($class) {
            // Only handle Modules\IoT namespace
            if (strpos($class, 'Modules\\IoT\\') !== 0) {
                return;
            }
            
            // Convert namespace to file path
            $classPath = str_replace('Modules\\IoT\\', '', $class);
            $classPath = str_replace('\\', '/', $classPath);
            $file = __DIR__ . '/../../src/' . $classPath . '.php';
            
            if (file_exists($file)) {
                require_once $file;
            }
        });
        
        // Register module config
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/iot.php',
            'iot'
        );

        // Register device clients as singletons
        $this->app->singleton(AgentHttpClient::class, function ($app) {
            return new AgentHttpClient();
        });

        $this->app->singleton(HttpDeviceClient::class, function ($app) {
            return new HttpDeviceClient();
        });

        $this->app->singleton(MqttDeviceClient::class, function ($app) {
            return new MqttDeviceClient();
        });

        $this->app->singleton(WebhookDeviceClient::class, function ($app) {
            return new WebhookDeviceClient();
        });

        // Register device command router
        $this->app->singleton(DeviceCommandRouter::class, function ($app) {
            return new DeviceCommandRouter(
                $app->make(AgentHttpClient::class),
                $app->make(MqttDeviceClient::class),
                $app->make(HttpDeviceClient::class),
                $app->make(WebhookDeviceClient::class)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Load module routes
        $this->loadRoutesFrom(__DIR__ . '/../../routes/api.php');
        
        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }
}
