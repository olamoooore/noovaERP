<?php

namespace Modules\FieldService\Contracts;

use App\Modules\Contracts\BaseModuleContract;

/**
 * FieldService module contract (ADC §5 — Module Contract adoption).
 */
class FieldServiceModuleContract extends BaseModuleContract
{
    public function getKey(): string
    {
        return 'field-service';
    }

    public function getName(): string
    {
        return 'Field Service';
    }

    public function getType(): string
    {
        return 'core';
    }

    public function getDescription(): string
    {
        return 'Field Service — work orders, technicians, teams, time logging, worksheets';
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
            'fieldservice.workorders.view', 'fieldservice.workorders.create',
            'fieldservice.technicians.manage',
        ];
    }

    public function getEvents(): array
    {
        return ['fieldservice.workorder.assigned', 'fieldservice.workorder.completed'];
    }

    public function getOnboardingConfig(): array
    {
        return [
            'required_setup_steps' => ['team_setup'],
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
            \Modules\FieldService\Models\WorkOrder::class,
            \Modules\FieldService\Models\Technician::class,
            \Modules\FieldService\Models\Team::class,
            \Modules\FieldService\Models\TimeLog::class,
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
