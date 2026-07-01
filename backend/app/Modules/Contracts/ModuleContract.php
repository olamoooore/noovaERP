<?php

namespace App\Modules\Contracts;

use Illuminate\Support\Collection;

/**
 * Module Contract Interface
 *
 * Every module MUST implement this contract to be loaded by the ModuleManager.
 * This ensures consistent module structure, dependency management, and platform compliance.
 */
interface ModuleContract
{
    /**
     * Get the unique module key (e.g., 'pos', 'crm', 'inventory')
     */
    public function getKey(): string;

    /**
     * Get the human-readable module name
     */
    public function getName(): string;

    /**
     * Get the module type: 'core', 'addon', 'feature'
     */
    public function getType(): string;

    /**
     * Get module description
     */
    public function getDescription(): string;

    /**
     * Get the module version
     */
    public function getVersion(): string;

    /**
     * Get list of module keys this module depends on
     *
     * @return array<int, string>
     */
    public function getDependencies(): array;

    /**
     * Get list of events this module dispatches
     *
     * @return array<int, string> Event class names
     */
    public function getEvents(): array;

    /**
     * Get list of permissions this module requires
     *
     * @return array<int, string> Permission names
     */
    public function getPermissions(): array;

    /**
     * Get the onboarding configuration for this module
     *
     * @return array{
     *   required_setup_steps: array<int, string>,
     *   default_settings: array<string, mixed>,
     *   recommended_roles: array<int, string>
     * }
     */
    public function getOnboardingConfig(): array;

    /**
     * Get activation rules - conditions that must be met to activate this module
     *
     * @return array{
     *   requires: array<int, string>,  // Module keys that must be active
     *   settings: array<string, mixed>, // Required settings
     *   min_tier: string|null           // Minimum plan tier required
     * }
     */
    public function getActivationRules(): array;

    /**
     * Get deactivation cleanup rules
     *
     * @return array{
     *   removes_data: bool,
     *   preserves_entities: array<int, string>,
     *   cleanup_jobs: array<int, string>
     * }
     */
    public function getDeactivationRules(): array;

    /**
     * Get list of entity classes owned by this module
     *
     * @return array<int, string> Fully qualified class names
     */
    public function getOwnedEntities(): array;

    /**
     * Get external mode configuration
     *
     * @return array{
     *   supports_coupled: bool,
     *   supports_decoupled: bool,
     *   supports_external: bool,
     *   external_api_endpoints: array<int, string>
     * }
     */
    public function getExternalModeConfig(): array;

    /**
     * Get AI capabilities this module exposes
     *
     * @return array{
     *   has_ai_assistant: bool,
     *   ai_suggestions: bool,
     *   ai_automation: bool,
     *   ai_data_access: array<int, string>
     * }
     */
    public function getAICapabilities(): array;

    /**
     * Check if this module is ready to be activated
     *
     * @return array{ready: bool, reasons: array<int, string>}
     */
    public function canActivate(): array;

    /**
     * Run activation logic
     */
    public function onActivate(): void;

    /**
     * Run deactivation logic
     */
    public function onDeactivate(): void;
}
