<?php

namespace App\Modules;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

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
        Log::debug('[ModuleManager] Scanning for modules', ['base_path' => $basePath]);
        
        if (!is_dir($basePath)) {
            Log::warning('[ModuleManager] Modules directory does not exist', ['base_path' => $basePath]);
            return $this->modulesCache = [];
        }

        $directories = File::directories($basePath);
        Log::debug('[ModuleManager] Found directories', ['count' => count($directories), 'directories' => $directories]);

        $modules = [];
        $skippedDirs = [];
        
        foreach ($directories as $dir) {
            $dirName = basename($dir);
            $manifestPath = $dir . '/module.json';
            
            if (!is_file($manifestPath)) {
                $skippedDirs[] = ['dir' => $dirName, 'reason' => 'module.json not found'];
                Log::debug('[ModuleManager] Skipping directory - no module.json', ['dir' => $dirName]);
                continue;
            }

            $raw = json_decode(file_get_contents($manifestPath) ?: '', true);
            if (!is_array($raw)) {
                $skippedDirs[] = ['dir' => $dirName, 'reason' => 'invalid JSON in module.json'];
                Log::warning('[ModuleManager] Skipping directory - invalid JSON', ['dir' => $dirName, 'manifest_path' => $manifestPath]);
                continue;
            }

            $key = (string) ($raw['key'] ?? '');
            $name = (string) ($raw['name'] ?? '');
            $type = (string) ($raw['type'] ?? 'addon');
            $providers = $raw['providers'] ?? [];

            if ($key === '' || $name === '') {
                $skippedDirs[] = ['dir' => $dirName, 'reason' => 'missing key or name in module.json'];
                Log::warning('[ModuleManager] Skipping directory - missing key or name', [
                    'dir' => $dirName,
                    'key' => $key,
                    'name' => $name,
                ]);
                continue;
            }

            $module = new Module(
                key: $key,
                name: $name,
                type: $type,
                basePath: $dir,
                providers: is_array($providers) ? $providers : [],
            );
            
            $modules[] = $module;
            Log::debug('[ModuleManager] Successfully discovered module', [
                'key' => $key,
                'name' => $name,
                'type' => $type,
                'dir' => $dirName,
            ]);
        }

        if (!empty($skippedDirs)) {
            Log::info('[ModuleManager] Skipped directories summary', ['skipped' => $skippedDirs]);
        }

        usort($modules, function (Module $a, Module $b): int {
            return [$a->isCore() ? 0 : 1, $a->key] <=> [$b->isCore() ? 0 : 1, $b->key];
        });

        Log::info('[ModuleManager] Module discovery complete', [
            'total_discovered' => count($modules),
            'total_skipped' => count($skippedDirs),
            'module_keys' => array_map(fn($m) => $m->key, $modules),
        ]);

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
