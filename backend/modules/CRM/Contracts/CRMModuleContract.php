<?php

namespace Modules\CRM\Contracts;

use App\Modules\Contracts\BaseModuleContract;

/**
 * CRM module contract (ADC §5 — Module Contract adoption).
 */
class CRMModuleContract extends BaseModuleContract
{
    public function getKey(): string { return 'crm'; }
    public function getName(): string { return 'NoovaERP CRM'; }
    public function getType(): string { return 'addon'; }
    public function getDescription(): string { return 'Customer Relationship Management — leads, accounts, opportunities, activities, sales handoff'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getDependencies(): array { return []; }

    public function getPermissions(): array
    {
        return [
            'crm.leads.view', 'crm.leads.create',
            'crm.accounts.view',
            'crm.opportunities.view', 'crm.opportunities.create',
            'crm.activities.manage',
        ];
    }

    public function getEvents(): array
    {
        return ['crm.lead.created', 'crm.opportunity.stage_changed', 'crm.account.updated'];
    }

    public function getOnboardingConfig(): array
    {
        return [
            'required_setup_steps' => ['pipeline_setup'],
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
