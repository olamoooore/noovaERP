<?php

namespace App\Modules;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\File;

final class ModuleManager
{
    /**
     * @var array<int, Module>|null
     */
    private ?array $modulesCache = null;

    public function __construct(
        private readonly Application $app,
    ) {
    }

    /**
     * @return array<int, Module>
     */
    public function all(): array
    {
        if ($this->modulesCache !== null) {
            return $this->modulesCache;
        }

        $basePath = base_path('modules');
        if (!is_dir($basePath)) {
            return $this->modulesCache = [];
        }

        $modules = [];
        foreach (File::directories($basePath) as $dir) {
            $manifestPath = $dir . '/module.json';
            if (!is_file($manifestPath)) {
                continue;
            }

            $raw = json_decode(file_get_contents($manifestPath) ?: '', true);
            if (!is_array($raw)) {
                continue;
            }

            $key = (string) ($raw['key'] ?? '');
            $name = (string) ($raw['name'] ?? '');
            $type = (string) ($raw['type'] ?? 'addon');
            $providers = $raw['providers'] ?? [];

            if ($key === '' || $name === '') {
                continue;
            }

            $modules[] = new Module(
                key: $key,
                name: $name,
                type: $type,
                basePath: $dir,
                providers: is_array($providers) ? $providers : [],
            );
        }


        usort($modules, function (Module $a, Module $b): int {
            return [$a->isCore() ? 0 : 1, $a->key] <=> [$b->isCore() ? 0 : 1, $b->key];
        });

        return $this->modulesCache = $modules;
    }

    /**
     * @return array<int, string>
     */
    public function tenantApiRouteFiles(): array
    {
        $paths = [];
        foreach ($this->all() as $module) {
            $path = $module->tenantApiRoutesPath();
            if ($path !== null) {
                $paths[] = $path;
            }
        }
        return $paths;
    }

    /**
     * @return array<int, string>
     */
    public function tenantMigrationPaths(): array
    {
        $paths = [];
        foreach ($this->all() as $module) {
            $path = $module->tenantMigrationsPath();
            if ($path !== null) {
                $paths[] = $path;
            }
        }
        return array_values(array_unique($paths));
    }

    public function registerModuleProviders(): void
    {
        foreach ($this->all() as $module) {
            foreach ($module->providers as $provider) {
                if (is_string($provider) && class_exists($provider)) {
                    $this->app->register($provider);
                }
            }
        }
    }
}
