<?php

namespace App\Modules;

use App\Modules\Services\ModuleOnboardingService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

final class ModuleManager
{
    /**
     * @var array<int, Module>|null
     */
    private ?array $modulesCache = null;

    private ModuleOnboardingService $onboardingService;

    public function __construct(
        private readonly Application $app,
    ) {
        $this->onboardingService = new ModuleOnboardingService;
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

        if (! is_dir($basePath)) {
            Log::warning('[ModuleManager] Modules directory does not exist', ['base_path' => $basePath]);

            return $this->modulesCache = [];
        }

        $directories = File::directories($basePath);
        Log::debug('[ModuleManager] Found directories', ['count' => count($directories), 'directories' => $directories]);

        $modules = [];
        $skippedDirs = [];

        foreach ($directories as $dir) {
            $dirName = basename($dir);
            $manifestPath = $dir.'/module.json';

            if (! is_file($manifestPath)) {
                $skippedDirs[] = ['dir' => $dirName, 'reason' => 'module.json not found'];
                Log::debug('[ModuleManager] Skipping directory - no module.json', ['dir' => $dirName]);

                continue;
            }

            $raw = json_decode(file_get_contents($manifestPath) ?: '', true);
            if (! is_array($raw)) {
                $skippedDirs[] = ['dir' => $dirName, 'reason' => 'invalid JSON in module.json'];
                Log::warning('[ModuleManager] Skipping directory - invalid JSON', ['dir' => $dirName, 'manifest_path' => $manifestPath]);

                continue;
            }

            $key = (string) ($raw['key'] ?? '');
            $name = (string) ($raw['name'] ?? '');
            $type = (string) ($raw['type'] ?? 'addon');
            $providers = $raw['providers'] ?? [];
            $dependencies = $raw['dependencies'] ?? [];

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
                dependencies: is_array($dependencies) ? $dependencies : [],
                deprecated: (bool) ($raw['deprecated'] ?? false),
            );

            $modules[] = $module;
            Log::debug('[ModuleManager] Successfully discovered module', [
                'key' => $key,
                'name' => $name,
                'type' => $type,
                'dir' => $dirName,
                'dependencies' => $dependencies,
            ]);
        }

        if (! empty($skippedDirs)) {
            Log::info('[ModuleManager] Skipped directories summary', ['skipped' => $skippedDirs]);
        }

        // Sort modules: core first, then by dependency order
        $modules = $this->sortByDependencies($modules);

        Log::info('[ModuleManager] Module discovery complete', [
            'total_discovered' => count($modules),
            'total_skipped' => count($skippedDirs),
            'module_keys' => array_map(fn ($m) => $m->key, $modules),
        ]);

        return $this->modulesCache = $modules;
    }

    /**
     * Sort modules by dependency order (dependencies first)
     *
     * @param  array<int, Module>  $modules
     * @return array<int, Module>
     */
    private function sortByDependencies(array $modules): array
    {
        $moduleKeys = array_map(fn ($m) => $m->key, $modules);
        $sorted = [];
        $visited = [];

        // Stable secondary sort: within each group (core vs addon) we want a
        // deterministic, alphabetical-by-key ordering. Without this, modules
        // discovered by `scandir` come out in filesystem order, which is
        // platform-dependent and breaks the module-discovery invariants
        // exercised by HrmPreservationPropertyTest.
        usort($modules, fn ($a, $b) => strcmp($a->key, $b->key));

        $visit = function (Module $module) use (&$visit, &$sorted, &$visited, $moduleKeys) {
            $key = $module->key;

            if (isset($visited[$key])) {
                return;
            }

            $visited[$key] = true;

            foreach ($module->dependencies as $depKey) {
                if (! in_array($depKey, $moduleKeys, true)) {
                    Log::warning("[ModuleManager] Module '{$key}' depends on unknown module '{$depKey}'");

                    continue;
                }

                // Find the dependency module
                foreach ($this->modulesCache ?? [] as $depModule) {
                    if ($depModule->key === $depKey) {
                        $visit($depModule);
                        break;
                    }
                }
            }

            $sorted[] = $module;
        };

        // First, add all core modules
        foreach ($modules as $module) {
            if ($module->isCore()) {
                $visit($module);
            }
        }

        // Then, add non-core modules
        foreach ($modules as $module) {
            if (! $module->isCore()) {
                $visit($module);
            }
        }

        return $sorted;
    }

    /**
     * Validate that all module dependencies are satisfied
     *
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validateDependencies(): array
    {
        $modules = $this->all();
        $moduleKeys = array_map(fn ($m) => $m->key, $modules);
        $errors = [];

        foreach ($modules as $module) {
            foreach ($module->dependencies as $depKey) {
                if (! in_array($depKey, $moduleKeys, true)) {
                    $errors[] = "Module '{$module->key}' depends on '{$depKey}' which is not installed";
                }
            }
        }

        // Check for circular dependencies
        $circularDeps = $this->detectCircularDependencies($modules);
        if (! empty($circularDeps)) {
            foreach ($circularDeps as $cycle) {
                $errors[] = 'Circular dependency detected: '.implode(' -> ', $cycle);
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Detect circular dependencies using DFS
     *
     * @param  array<int, Module>  $modules
     * @return array<int, array<int, string>>
     */
    private function detectCircularDependencies(array $modules): array
    {
        $moduleMap = [];
        foreach ($modules as $module) {
            $moduleMap[$module->key] = $module;
        }

        $cycles = [];
        $visited = [];
        $recStack = [];

        $dfs = function (string $key, array $path) use (&$dfs, &$visited, &$recStack, &$cycles, $moduleMap) {
            if (isset($recStack[$key])) {
                // Found a cycle
                $cycleStart = array_search($key, $path);
                $cycles[] = array_slice($path, $cycleStart);

                return;
            }

            if (isset($visited[$key])) {
                return;
            }

            $visited[$key] = true;
            $recStack[$key] = true;
            $path[] = $key;

            if (isset($moduleMap[$key])) {
                foreach ($moduleMap[$key]->dependencies as $depKey) {
                    $dfs($depKey, $path);
                }
            }

            unset($recStack[$key]);
        };

        foreach ($modules as $module) {
            $dfs($module->key, []);
        }

        return $cycles;
    }

    /**
     * Get a module by key
     */
    public function get(string $key): ?Module
    {
        foreach ($this->all() as $module) {
            if ($module->key === $key) {
                return $module;
            }
        }

        return null;
    }

    /**
     * Get all modules that depend on a specific module
     *
     * @return array<int, Module>
     */
    public function getDependents(string $moduleKey): array
    {
        return array_filter(
            $this->all(),
            fn (Module $module) => $module->dependsOn($moduleKey)
        );
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

    /**
     * Get onboarding pack for a module
     */
    public function getOnboardingPack(string $moduleKey): ?array
    {
        $module = $this->get($moduleKey);
        if (! $module) {
            return null;
        }

        return $this->onboardingService->getOnboardingPack($module);
    }

    /**
     * Check onboarding status for a tenant/module
     */
    public function checkOnboardingStatus(string $tenantId, string $moduleKey): array
    {
        return $this->onboardingService->checkOnboardingStatus($tenantId, $moduleKey);
    }

    /**
     * Complete an onboarding step
     */
    public function completeOnboardingStep(string $tenantId, string $moduleKey, string $step): bool
    {
        return $this->onboardingService->completeStep($tenantId, $moduleKey, $step);
    }

    /**
     * Get all module contracts in structured format
     */
    public function getModuleContracts(): array
    {
        $contracts = [];

        foreach ($this->all() as $module) {
            $contracts[] = [
                'key' => $module->key,
                'name' => $module->name,
                'type' => $module->type,
                'dependencies' => $module->dependencies,
                'version' => '1.0.0',
            ];
        }

        return $contracts;
    }
}
