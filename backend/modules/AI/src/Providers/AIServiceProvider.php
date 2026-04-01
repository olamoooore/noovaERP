<?php

namespace Modules\AI\Providers;

use Illuminate\Support\ServiceProvider;

class AIServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register config
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/ai.php',
            'ai'
        );

        // Register services
        $this->app->singleton(\Modules\AI\Services\OllamaService::class);
        $this->app->singleton(\Modules\AI\Services\FunctionRouter::class);
        $this->app->singleton(\Modules\AI\Services\SubModuleManager::class);
        $this->app->singleton(\Modules\AI\Services\CommonQuestionsService::class);
        $this->app->singleton(\Modules\AI\Services\ChatbotService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Routes are loaded via tenant.php through ModuleManager->tenantApiRouteFiles()
        // Do NOT load routes here to avoid duplicate/incorrect prefix issues

        // Load central migrations only
        // Tenant migrations are loaded via tenancy.php migration_parameters config.
        $this->loadMigrationsFrom([
            __DIR__ . '/../../database/migrations',
        ]);
    }
}
