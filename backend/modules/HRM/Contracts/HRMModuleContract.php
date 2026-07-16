<?php

namespace Modules\HRM\Contracts;

use App\Modules\Contracts\BaseModuleContract;

/**
 * HRM module contract (ADC §5 — Module Contract adoption).
 */
class HRMModuleContract extends BaseModuleContract
{
    public function getKey(): string
    {
        return 'hrm';
    }

    public function getName(): string
    {
        return 'NoovaERP HRM';
    }

    public function getType(): string
    {
        return 'addon';
    }

    public function getDescription(): string
    {
        return 'Human Resource Management — employees, contracts, payroll, attendance, and recruitment';
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
            'hrm.employees.view', 'hrm.employees.create',
            'hrm.payroll.manage', 'hrm.attendance.view',
        ];
    }

    public function getEvents(): array
    {
        return ['hrm.employee.hired', 'hrm.payroll.processed', 'hrm.leave.approved'];
    }

    public function getOnboardingConfig(): array
    {
        return [
            'required_setup_steps' => ['employee_setup'],
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
            \Modules\HRM\Models\Employee::class,
            \Modules\HRM\Models\Contract::class,
            \Modules\HRM\Models\Payroll::class,
            \Modules\HRM\Models\Attendance::class,
            \Modules\HRM\Models\LeaveRequest::class,
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
