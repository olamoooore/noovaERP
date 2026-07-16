<?php

namespace App\Modules\Contracts;

use Illuminate\Support\Facades\Log;

/**
 * Base Module Implementation
 *
 * Abstract base class providing default implementations for ModuleContract.
 * Modules should extend this class and override methods as needed.
 */
abstract class BaseModule implements ModuleContract
{
    protected string $key;

    protected string $name;

    protected string $type = 'addon';

    protected string $description = '';

    protected string $version = '1.0.0';

    public function getKey(): string
    {
        return $this->key;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getDependencies(): array
    {
        return [];
    }

    public function getEvents(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [];
    }

    public function getOnboardingConfig(): array
    {
        return [
            'required_setup_steps' => [],
            'default_settings' => [],
            'recommended_roles' => ['admin', 'user'],
        ];
    }

    public function getActivationRules(): array
    {
        return [
            'requires' => [],
            'settings' => [],
            'min_tier' => null,
        ];
    }

    public function getDeactivationRules(): array
    {
        return [
            'removes_data' => false,
            'preserves_entities' => [],
            'cleanup_jobs' => [],
        ];
    }

    public function getOwnedEntities(): array
    {
        return [];
    }

    public function getExternalModeConfig(): array
    {
        return [
            'supports_coupled' => true,
            'supports_decoupled' => false,
            'supports_external' => false,
            'external_api_endpoints' => [],
        ];
    }

    public function getAICapabilities(): array
    {
        return [
            'has_ai_assistant' => false,
            'ai_suggestions' => false,
            'ai_automation' => false,
            'ai_data_access' => [],
        ];
    }

    public function canActivate(): array
    {
        $reasons = [];

        // Check dependencies
        foreach ($this->getDependencies() as $depKey) {
            $depModule = app('modules')->get($depKey);
            if (! $depModule) {
                $reasons[] = "Required module '{$depKey}' is not installed";
            }
        }

        // Check tier
        $activationRules = $this->getActivationRules();
        if ($activationRules['min_tier'] !== null) {
            $currentTier = $this->getCurrentTier();
            if ($currentTier === null) {
                $reasons[] = 'No subscription tier found';
            } elseif (! $this->tierMeetsMinimum($currentTier, $activationRules['min_tier'])) {
                $reasons[] = "Requires minimum tier '{$activationRules['min_tier']}', current tier is '{$currentTier}'";
            }
        }

        return [
            'ready' => empty($reasons),
            'reasons' => $reasons,
        ];
    }

    public function onActivate(): void
    {
        Log::info("Module '{$this->key}' activated", [
            'module' => $this->key,
            'version' => $this->version,
        ]);
    }

    public function onDeactivate(): void
    {
        Log::info("Module '{$this->key}' deactivated", [
            'module' => $this->key,
        ]);
    }

    protected function getCurrentTier(): ?string
    {
        // Implementation depends on your subscription system
        return config('app.tier', 'standard');
    }

    protected function tierMeetsMinimum(string $current, string $required): bool
    {
        $tiers = ['free' => 0, 'standard' => 1, 'premium' => 2, 'enterprise' => 3];

        return ($tiers[$current] ?? 0) >= ($tiers[$required] ?? 0);
    }
}
