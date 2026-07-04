<?php

namespace Modules\Core\Contracts;

use App\Modules\Contracts\BaseModuleContract;

/**
 * Core platform module contract (ADC §5 — Module Contract adoption).
 *
 * Declares platform-level metadata: permissions, onboarding steps,
 * activation rules, and the entity ownership list for the Core module.
 */
class CoreModuleContract extends BaseModuleContract
{
    public function getKey(): string
    {
        return 'core';
    }

    public function getName(): string
    {
        return 'Core';
    }

    public function getType(): string
    {
        return 'core';
    }

    public function getDescription(): string
    {
        return 'Platform core: users, roles, tenants, settings, onboarding.';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDependencies(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'users.view', 'users.create', 'users.edit', 'users.delete',
            'roles.view', 'roles.manage',
            'settings.view', 'settings.edit',
        ];
    }

    public function getEvents(): array
    {
        return [
            'user.created',
            'user.updated',
        ];
    }

    public function getOnboardingConfig(): array
    {
        return [
            'required_setup_steps' => ['group_setup', 'entity_selection', 'country_compliance'],
            'default_settings'     => ['date_format' => 'Y-m-d', 'timezone' => 'UTC'],
            'recommended_roles'    => ['admin', 'accountant'],
        ];
    }

    public function getOwnedEntities(): array
    {
        return [
            \App\Models\User::class,
            \App\Models\Tenant::class,
        ];
    }
}
