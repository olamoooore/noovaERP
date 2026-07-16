<?php

namespace Modules\FinancialClose\Contracts;

use App\Modules\Contracts\BaseModuleContract;

/**
 * FinancialClose module contract (ADC §5 — Module Contract adoption).
 */
class FinancialCloseModuleContract extends BaseModuleContract
{
    public function getKey(): string
    {
        return 'financial-close';
    }

    public function getName(): string
    {
        return 'FinancialClose';
    }

    public function getType(): string
    {
        return 'feature';
    }

    public function getDescription(): string
    {
        return 'Financial Close & Period-End — close cycles, tasks, reconciliations, signoffs, evidence packs';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDependencies(): array
    {
        return ['core'];
    }

    public function getPermissions(): array
    {
        return [
            'financialclose.cycles.view', 'financialclose.tasks.manage',
            'financialclose.signoffs.manage', 'financialclose.evidence.view',
        ];
    }

    public function getEvents(): array
    {
        return ['financialclose.cycle.started', 'financialclose.task.completed', 'financialclose.signoff.approved'];
    }

    public function getOnboardingConfig(): array
    {
        return [
            'required_setup_steps' => ['period_end_setup'],
            'default_settings' => [],
            'recommended_roles' => ['admin'],
        ];
    }

    public function getActivationRules(): array
    {
        return [
            'requires' => ['core'],
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
        return [
            \Modules\FinancialClose\Models\CloseCycle::class,
            \Modules\FinancialClose\Models\CloseTask::class,
            \Modules\FinancialClose\Models\Signoff::class,
            \Modules\FinancialClose\Models\EvidencePack::class,
            \Modules\FinancialClose\Models\AccountReview::class,
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
}
