<?php

namespace Modules\Loyalty\Contracts;

use App\Modules\Contracts\BaseModuleContract;

/**
 * Loyalty module contract (ADC §5 — Module Contract adoption).
 */
class LoyaltyModuleContract extends BaseModuleContract
{
    public function getKey(): string
    {
        return 'loyalty';
    }

    public function getName(): string
    {
        return 'Loyalty';
    }

    public function getType(): string
    {
        return 'addon';
    }

    public function getDescription(): string
    {
        return 'Loyalty programs — points, tiers, rewards, redemptions, customer loyalty';
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
            'loyalty.programs.view', 'loyalty.programs.manage',
            'loyalty.rewards.manage',
        ];
    }

    public function getEvents(): array
    {
        return ['loyalty.points.earned', 'loyalty.points.redeemed', 'loyalty.tier.upgraded'];
    }

    public function getOnboardingConfig(): array
    {
        return [
            'required_setup_steps' => ['program_setup'],
            'default_settings' => [],
            'recommended_roles' => ['admin'],
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
        return [
            \Modules\Loyalty\Models\LoyaltyProgram::class,
            \Modules\Loyalty\Models\LoyaltyTier::class,
            \Modules\Loyalty\Models\LoyaltyTransaction::class,
            \Modules\Loyalty\Models\LoyaltyReward::class,
            \Modules\Loyalty\Models\CustomerLoyalty::class,
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
