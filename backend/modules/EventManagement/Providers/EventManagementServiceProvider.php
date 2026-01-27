<?php

namespace Modules\EventManagement\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Modules\EventManagement\Http\Middleware\FeatureFlagMiddleware;
use Modules\EventManagement\Http\Middleware\RBACMiddleware;
use Modules\EventManagement\Http\Middleware\EventManagementRateLimitMiddleware;
use Modules\EventManagement\Http\Middleware\RequestValidationMiddleware;
use Modules\EventManagement\Services\EventService;
use Modules\EventManagement\Services\BookingService;
use Modules\EventManagement\Services\TicketService;
use Modules\EventManagement\Services\AuditService;
use Modules\EventManagement\Services\FeatureFlagController;
use Modules\EventManagement\Services\TenantSchemaManager;
use Modules\EventManagement\Services\DataRetentionService;
use Modules\EventManagement\Services\MigrationMonitoringService;
use Modules\EventManagement\Services\MigrationDependencyService;
use Modules\EventManagement\Console\Commands\CleanupAuditLogsCommand;
use Modules\EventManagement\Console\Commands\ValidateMigrationTimestampsCommand;
use Modules\EventManagement\Console\Commands\SafeMigrateCommand;
use Modules\EventManagement\Console\Commands\MigrateExistingEventsCommand;

class EventManagementServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register core services
        $this->app->singleton(EventService::class);
        $this->app->singleton(BookingService::class);
        $this->app->singleton(TicketService::class);
        $this->app->singleton(AuditService::class);
        $this->app->singleton(FeatureFlagController::class);
        $this->app->singleton(TenantSchemaManager::class);
        $this->app->singleton(DataRetentionService::class);
        $this->app->singleton(MigrationMonitoringService::class);
        $this->app->singleton(MigrationDependencyService::class);
        $this->app->singleton(\Modules\EventManagement\Services\DataEncryptionService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');

        // Register middleware
        $this->app['router']->aliasMiddleware('feature.event_management', FeatureFlagMiddleware::class);
        $this->app['router']->aliasMiddleware('rbac.event_management', RBACMiddleware::class);
        $this->app['router']->aliasMiddleware('rate_limit', EventManagementRateLimitMiddleware::class);
        $this->app['router']->aliasMiddleware('request.validation', RequestValidationMiddleware::class);

        // Publish configuration
        $this->publishes([
            __DIR__ . '/../Config/event_management.php' => config_path('event_management.php'),
        ], 'event-management-config');

        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                CleanupAuditLogsCommand::class,
                ValidateMigrationTimestampsCommand::class,
                SafeMigrateCommand::class,
                MigrateExistingEventsCommand::class,
            ]);
        }
    }
}