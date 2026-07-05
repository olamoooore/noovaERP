<?php

namespace Modules\AccountingCountry\Contracts;

use App\Modules\Contracts\BaseModuleContract;

/**
 * AccountingCountry module contract (ADC §5 — Module Contract adoption).
 */
class AccountingCountryModuleContract extends BaseModuleContract
{
    public function getKey(): string { return 'AccountingCountry'; }
    public function getName(): string { return 'Accounting Country Compliance'; }
    public function getType(): string { return 'addon'; }
    public function getDescription(): string { return 'Country-specific statutory reporting plugins for XBRL/compliance exports'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getDependencies(): array { return []; }

    public function getPermissions(): array
    {
        return [
            'accounting.country.view', 'accounting.country.manage',
        ];
    }

    public function getEvents(): array
    {
        return ['accounting.country.compliance_checked'];
    }

    public function getOnboardingConfig(): array
    {
        return [
            'required_setup_steps' => ['country_compliance_setup'],
            'default_settings'     => [],
            'recommended_roles'    => ['admin'],
        ];
    }

    public function getActivationRules(): array
    {
        return [
            'requires'  => [],
            'settings'  => [],
            'min_tier'  => null,
        ];
    }

    public function getDeactivationRules(): array
    {
        return [
            'removes_data'       => false,
            'preserves_entities' => [],
            'cleanup_jobs'       => [],
        ];
    }

    public function getOwnedEntities(): array
    {
        return [];
    }

    public function getAICapabilities(): array
    {
        return [
            'has_ai_assistant' => false,
            'ai_suggestions'   => false,
            'ai_automation'    => false,
            'ai_data_access'   => [],
        ];
    }
}
