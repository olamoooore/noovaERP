<?php

namespace App\Modules\Contracts;

/**
 * Shared base implementing ModuleContract — each module extends this
 * and overrides the manifest/accessors relevant to it.
 */
abstract class BaseModuleContract implements ModuleContract
{
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
            'recommended_roles' => [],
        ];
    }

    public function getActivationRules(): array
    {
        return [
            'requires' => $this->getDependencies(),
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
        return ['ready' => true, 'reasons' => []];
    }

    public function onActivate(): void {}

    public function onDeactivate(): void {}
}
