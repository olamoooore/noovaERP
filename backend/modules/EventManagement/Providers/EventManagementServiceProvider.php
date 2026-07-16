<?php

namespace Modules\EventManagement\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\EventManagement\Console\Commands\CleanupAuditLogsCommand;
use Modules\EventManagement\Console\Commands\MigrateExistingEventsCommand;
use Modules\EventManagement\Console\Commands\SafeMigrateCommand;
use Modules\EventManagement\Console\Commands\SecurityAuditCommand;
use Modules\EventManagement\Console\Commands\ValidateMigrationTimestampsCommand;
use Modules\EventManagement\Http\Middleware\EventManagementRateLimitMiddleware;
use Modules\EventManagement\Http\Middleware\RBACMiddleware;
use Modules\EventManagement\Http\Middleware\RequestValidationMiddleware;
use Modules\EventManagement\Services\AuditService;
use Modules\EventManagement\Services\BookingService;
use Modules\EventManagement\Services\DataRetentionService;
use Modules\EventManagement\Services\EventService;
use Modules\EventManagement\Services\MigrationDependencyService;
use Modules\EventManagement\Services\MigrationMonitoringService;
use Modules\EventManagement\Services\TenantSchemaManager;
use Modules\EventManagement\Services\TicketService;

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
        // Do NOT also loadMigrationsFrom here or they will run during central migrations.
        // Tenant migrations are loaded via tenancy.php migration_parameters config
        // and explicitly via TenantTestCase::getTenantMigrationPaths() in tests.

        // Routes are loaded via tenant.php (ModuleManager::tenantApiRouteFiles)
        // Do NOT use loadRoutesFrom here to avoid double-prefix registration

        // Register middleware
        // Note: 'feature.event_management' alias removed — all routes now use 'entitlement:event_management'
        $this->app['router']->aliasMiddleware('rbac.event_management', RBACMiddleware::class);
        $this->app['router']->aliasMiddleware('rate_limit', EventManagementRateLimitMiddleware::class);
        $this->app['router']->aliasMiddleware('request.validation', RequestValidationMiddleware::class);

        // Publish configuration
        $this->publishes([
            __DIR__.'/../Config/event_management.php' => config_path('event_management.php'),
        ], 'event-management-config');

        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                CleanupAuditLogsCommand::class,
                ValidateMigrationTimestampsCommand::class,
                SafeMigrateCommand::class,
                MigrateExistingEventsCommand::class,
                SecurityAuditCommand::class,
            ]);
        }
    }
}
