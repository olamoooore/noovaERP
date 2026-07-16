<?php

namespace Modules\FinancialReporting\Contracts;

use App\Modules\Contracts\BaseModuleContract;

/**
 * FinancialReporting module contract (ADC §5 — Module Contract adoption).
 */
class FinancialReportingModuleContract extends BaseModuleContract
{
    public function getKey(): string
    {
        return 'financial-reporting';
    }

    public function getName(): string
    {
        return 'FinancialReporting';
    }

    public function getType(): string
    {
        return 'addon';
    }

    public function getDescription(): string
    {
        return 'Financial Reporting & Analytics — reports, dashboards, templates, scheduled reports';
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
            'reporting.reports.view', 'reporting.reports.create',
            'reporting.schedules.manage',
        ];
    }

    public function getEvents(): array
    {
        return ['reporting.report.generated', 'reporting.report.scheduled'];
    }

    public function getOnboardingConfig(): array
    {
        return [
            'required_setup_steps' => ['report_setup'],
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
            \Modules\FinancialReporting\Models\ReportCache::class,
            \Modules\FinancialReporting\Models\ReportTemplate::class,
            \Modules\FinancialReporting\Models\ReportSchedule::class,
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
