<?php

namespace Modules\FinancialReporting\Providers;

use Illuminate\Support\ServiceProvider;

class FinancialReportingServiceProvider extends ServiceProvider
{
    protected string $moduleName = 'FinancialReporting';

    protected string $moduleNameLower = 'financialreporting';

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->registerConfig();
        $this->registerViews();
        // Do NOT also loadMigrationsFrom here or they will run during central migrations.
        // Tenant migrations are loaded via tenancy.php migration_parameters config
        // and explicitly via TenantTestCase::getTenantMigrationPaths() in tests.
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        // Routes are automatically loaded via tenant.php
        // No need to register RouteServiceProvider
    }

    /**
     * Register config.
     */
    protected function registerConfig(): void
    {
        $configPath = __DIR__.'/../../config/config.php';

        $this->publishes([
            $configPath => config_path($this->moduleNameLower.'.php'),
        ], 'config');

        $this->mergeConfigFrom($configPath, $this->moduleNameLower);
    }

    /**
     * Register views.
     */
    public function registerViews(): void
    {
        $viewPath = resource_path('views/modules/'.$this->moduleNameLower);
        $sourcePath = __DIR__.'/../../resources/views';

        $this->publishes([
            $sourcePath => $viewPath,
        ], ['views', $this->moduleNameLower.'-module-views']);

        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->moduleNameLower);
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [];
    }

    private function getPublishableViewPaths(): array
    {
        $paths = [];
        foreach ($this->app['config']->get('view.paths') as $path) {
            if (is_dir($path.'/modules/'.$this->moduleNameLower)) {
                $paths[] = $path.'/modules/'.$this->moduleNameLower;
            }
        }

        return $paths;
    }
}
