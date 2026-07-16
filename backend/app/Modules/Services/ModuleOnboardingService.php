<?php

namespace App\Modules\Services;

use App\Modules\Contracts\ModuleContract;

/**
 * Module Onboarding Service
 *
 * Manages onboarding packs for modules:
 * - Setup steps
 * - Default configurations
 * - Role assignments
 * - Welcome content
 */
class ModuleOnboardingService
{
    /**
     * Get the onboarding pack for a module
     */
    public function getOnboardingPack(ModuleContract $module): array
    {
        $config = $module->getOnboardingConfig();

        return [
            'module_key' => $module->getKey(),
            'module_name' => $module->getName(),
            'version' => $module->getVersion(),
            'setup_steps' => $this->buildSetupSteps($module),
            'default_settings' => $config['default_settings'] ?? [],
            'recommended_roles' => $config['recommended_roles'] ?? [],
            'welcome_message' => $this->getWelcomeMessage($module),
            'quick_start_guide' => $this->getQuickStartGuide($module),
            'video_tutorials' => $this->getVideoTutorials($module),
        ];
    }

    /**
     * Check if onboarding is complete for a tenant/module
     *
     * @return array{complete: bool, progress: float, pending_steps: array}
     */
    public function checkOnboardingStatus(string $tenantId, string $moduleKey): array
    {
        $progress = \DB::table('module_onboarding_progress')
            ->where('tenant_id', $tenantId)
            ->where('module_key', $moduleKey)
            ->first();

        if (! $progress) {
            return [
                'complete' => false,
                'progress' => 0,
                'pending_steps' => $this->getAllSteps($moduleKey),
            ];
        }

        $completedSteps = json_decode($progress->completed_steps, true) ?? [];
        $allSteps = $this->getAllSteps($moduleKey);
        $pendingSteps = array_diff($allSteps, $completedSteps);

        return [
            'complete' => empty($pendingSteps),
            'progress' => count($completedSteps) / max(count($allSteps), 1) * 100,
            'pending_steps' => array_values($pendingSteps),
        ];
    }

    /**
     * Mark a setup step as complete
     */
    public function completeStep(string $tenantId, string $moduleKey, string $step): bool
    {
        $progress = \DB::table('module_onboarding_progress')
            ->where('tenant_id', $tenantId)
            ->where('module_key', $moduleKey)
            ->first();

        $completedSteps = $progress ? (json_decode($progress->completed_steps, true) ?? []) : [];

        if (! in_array($step, $completedSteps)) {
            $completedSteps[] = $step;
        }

        $allSteps = $this->getAllSteps($moduleKey);
        $isComplete = empty(array_diff($allSteps, $completedSteps));

        if ($progress) {
            \DB::table('module_onboarding_progress')
                ->where('id', $progress->id)
                ->update([
                    'completed_steps' => json_encode($completedSteps),
                    'is_complete' => $isComplete,
                    'completed_at' => $isComplete ? now() : null,
                    'updated_at' => now(),
                ]);
        } else {
            \DB::table('module_onboarding_progress')->insert([
                'tenant_id' => $tenantId,
                'module_key' => $moduleKey,
                'completed_steps' => json_encode($completedSteps),
                'is_complete' => $isComplete,
                'completed_at' => $isComplete ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $isComplete;
    }

    /**
     * Build setup steps for a module
     */
    private function buildSetupSteps(ModuleContract $module): array
    {
        $steps = [];

        // Common steps
        $steps[] = [
            'id' => 'review_settings',
            'title' => 'Review Module Settings',
            'description' => 'Configure the module settings to match your business needs.',
            'type' => 'configuration',
        ];

        $steps[] = [
            'id' => 'setup_roles',
            'title' => 'Set Up User Roles',
            'description' => 'Assign appropriate roles and permissions to your team members.',
            'type' => 'permissions',
        ];

        // Module-specific steps
        foreach ($module->getOnboardingConfig()['required_setup_steps'] ?? [] as $stepId) {
            $steps[] = [
                'id' => $stepId,
                'title' => $this->formatStepTitle($stepId),
                'description' => $this->getStepDescription($stepId),
                'type' => 'module_specific',
            ];
        }

        return $steps;
    }

    /**
     * Get all step IDs for a module
     */
    private function getAllSteps(string $moduleKey): array
    {
        return ['review_settings', 'setup_roles'];
    }

    /**
     * Get welcome message for a module
     */
    private function getWelcomeMessage(ModuleContract $module): string
    {
        return "Welcome to {$module->getName()}! This module will help you manage ".
               strtolower($module->getDescription()).". Let's get you set up in just a few steps.";
    }

    /**
     * Get quick start guide
     */
    private function getQuickStartGuide(ModuleContract $module): array
    {
        return [
            'title' => "Quick Start: {$module->getName()}",
            'steps' => [
                'Review and configure your settings',
                'Set up user roles and permissions',
                'Import or create your first records',
                'Start using the module!',
            ],
        ];
    }

    /**
     * Get video tutorials
     */
    private function getVideoTutorials(ModuleContract $module): array
    {
        return [
            [
                'title' => "Getting Started with {$module->getName()}",
                'url' => "/tutorials/{$module->getKey()}/getting-started",
                'duration' => '5:00',
            ],
        ];
    }

    /**
     * Format step ID to title
     */
    private function formatStepTitle(string $stepId): string
    {
        return ucwords(str_replace('_', ' ', $stepId));
    }

    /**
     * Get step description
     */
    private function getStepDescription(string $stepId): string
    {
        $descriptions = [
            'setup_roles' => 'Configure who can access and modify data in this module.',
            'review_settings' => 'Review and adjust the default settings for your needs.',
        ];

        return $descriptions[$stepId] ?? 'Complete this setup step.';
    }
}
