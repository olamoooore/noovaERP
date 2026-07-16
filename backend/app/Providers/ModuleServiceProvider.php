<?php

namespace App\Providers;

use App\Modules\ModuleManager;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleManager::class);
    }

    public function boot(ModuleManager $modules): void
    {
        // Module resources (views/lang/config) to support "plugin-like" packaging.
        foreach ($modules->all() as $module) {
            $views = $module->viewsPath();
            if ($views !== null) {
                $this->loadViewsFrom($views, $module->key);
            }

            $lang = $module->translationsPath();
            if ($lang !== null) {
                $this->loadTranslationsFrom($lang, $module->key);
            }

            $config = $module->configPath();
            if ($config !== null) {
                $this->mergeConfigFrom($config, $module->key);
            }
        }

        // Ensure module migrations are discoverable by stancl/tenancy's tenants:migrate command.
        $existing = config('tenancy.migration_parameters.--path', []);
        if (! is_array($existing)) {
            $existing = [];
        }

        $merged = array_values(array_unique(array_merge($existing, $modules->tenantMigrationPaths())));
        config(['tenancy.migration_parameters.--path' => $merged]);

        // Register each module's service provider(s).
        $modules->registerModuleProviders();
    }
}
