<?php

namespace Modules\AI\Contracts;

use App\Modules\Contracts\BaseModuleContract;

/**
 * AI module contract (ADC §5 — Module Contract adoption).
 */
class AIModuleContract extends BaseModuleContract
{
    public function getKey(): string { return 'ai'; }
    public function getName(): string { return 'AI'; }
    public function getType(): string { return 'addon'; }
    public function getDescription(): string { return 'AI-powered assistant, reasoning, and automation engine.'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getDependencies(): array { return []; }

    public function getPermissions(): array
    {
        return [
            'ai.chat', 'ai.approve', 'ai.reasoning.view',
        ];
    }

    public function getEvents(): array
    {
        return [
            'ai.reasoning.created',
            'ai.approval.requested',
            'ai.approval.decided',
        ];
    }

    public function getOnboardingConfig(): array
    {
        return [
            'required_setup_steps' => ['api_key_setup', 'policy_review'],
            'default_settings'     => ['risk_threshold' => 'medium', 'max_cost_per_request' => 0.05],
            'recommended_roles'    => ['admin', 'ai_operator'],
        ];
    }

    public function getActivationRules(): array
    {
        return [
            'requires'  => [],
            'settings'  => ['api_key' => null],
            'min_tier'  => null,
        ];
    }

    public function getDeactivationRules(): array
    {
        return [
            'removes_data'       => false,
            'preserves_entities' => ['ai_reasoning_records'],
            'cleanup_jobs'       => ['cancel_pending_approvals'],
        ];
    }

    public function getOwnedEntities(): array
    {
        return [
            \App\Models\AIReasoningRecord::class,
            \App\Models\AIApprovalRequest::class,
        ];
    }

    public function getAICapabilities(): array
    {
        return [
            'has_ai_assistant' => true,
            'ai_suggestions'   => true,
            'ai_automation'    => true,
            'ai_data_access'   => ['all_modules'],
        ];
    }

    public function canActivate(): array
    {
        return ['ready' => true, 'reasons' => []];
    }
}
