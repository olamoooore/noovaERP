<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Modules\ModuleManager;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Platform Module API (ADC §5, §7 — Module Contract / activation).
 *
 * Public, standardized routes wired to ModuleManager:
 *   GET    /api/v1/modules                       -> list discovered modules
 *   GET    /api/v1/modules/{key}                 -> module detail + dependents
 *   GET    /api/v1/modules/dependencies/validate -> dependency graph validation
 *   POST   /api/v1/modules/{key}/activate        -> dependency-aware activation
 *   POST   /api/v1/modules/{key}/deactivate      -> blocks if dependents exist
 */
class PlatformModuleController
{
    use ApiResponse;

    public function __construct(
        private readonly ModuleManager $modules,
    ) {}

    public function index(): JsonResponse
    {
        $modules = array_map(fn ($m) => [
            'key' => $m->key,
            'name' => $m->name,
            'type' => $m->type,
            'is_core' => $m->isCore(),
            'dependencies' => $m->dependencies,
        ], $this->modules->all());

        return $this->success($modules, 'Discovered modules.');
    }

    public function show(string $key): JsonResponse
    {
        $module = $this->modules->get($key);
        if (! $module) {
            return $this->notFound("Module '{$key}' not found.");
        }

        $dependents = array_map(fn ($d) => $d->key, $this->modules->getDependents($key));

        return $this->success([
            'key' => $module->key,
            'name' => $module->name,
            'type' => $module->type,
            'is_core' => $module->isCore(),
            'dependencies' => $module->dependencies,
            'dependents' => $dependents,
        ], 'Module detail.');
    }

    public function validateDependencies(): JsonResponse
    {
        $result = $this->modules->validateDependencies();

        return $result['valid']
            ? $this->success($result, 'All module dependencies are satisfied.')
            : $this->error('Module dependency validation failed.', 422, $result['errors']);
    }

    public function activate(string $key): JsonResponse
    {
        $module = $this->modules->get($key);
        if (! $module) {
            return $this->notFound("Module '{$key}' not found.");
        }

        // Enforce dependencies: every declared dependency must be installed.
        $missing = array_values(array_filter(
            $module->dependencies,
            fn ($depKey) => $this->modules->get($depKey) === null,
        ));

        if (! empty($missing)) {
            return $this->error(
                "Module '{$key}' cannot be activated: missing dependencies.",
                422,
                ['missing_dependencies' => $missing, 'dependencies' => $module->dependencies],
            );
        }

        return $this->success([
            'key' => $module->key,
            'activated' => true,
            'dependencies' => $module->dependencies,
        ], "Module '{$key}' is ready to activate (all dependencies present).");
    }

    public function deactivate(string $key): JsonResponse
    {
        $module = $this->modules->get($key);
        if (! $module) {
            return $this->notFound("Module '{$key}' not found.");
        }

        $dependents = array_map(fn ($d) => $d->key, $this->modules->getDependents($key));
        if (! empty($dependents)) {
            return $this->error(
                "Module '{$key}' cannot be deactivated: other modules depend on it.",
                422,
                ['dependents' => $dependents],
            );
        }

        return $this->success([
            'key' => $module->key,
            'deactivated' => true,
        ], "Module '{$key}' is safe to deactivate (no dependents).");
    }
}
