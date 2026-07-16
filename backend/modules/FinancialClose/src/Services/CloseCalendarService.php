<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Models\CalendarMilestone;
use Modules\FinancialClose\Models\CalendarOverride;
use Modules\FinancialClose\Models\CalendarTemplate;

class CloseCalendarService
{
    /**
     * Get all calendar templates for a tenant
     */
    public function listTemplates(string $tenantId): array
    {
        return CalendarTemplate::where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get()
            ->toArray();
    }

    /**
     * Get a single calendar template
     */
    public function getTemplate(string $tenantId, string $templateId): ?CalendarTemplate
    {
        return CalendarTemplate::where('tenant_id', $tenantId)
            ->where('id', $templateId)
            ->first();
    }

    /**
     * Create a calendar template
     */
    public function createTemplate(string $tenantId, string $name, string $calendarMode = 'business_day', array $milestoneDefinitions = []): ?CalendarTemplate
    {
        return CalendarTemplate::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'status' => 'draft',
            'calendar_mode' => $calendarMode,
            'milestone_definitions' => $milestoneDefinitions,
        ]);
    }

    /**
     * Activate a calendar template
     */
    public function activateTemplate(string $tenantId, string $templateId): bool
    {
        $template = $this->getTemplate($tenantId, $templateId);

        if (! $template || $template->status !== 'draft') {
            Log::warning('FinancialClose: cannot activate calendar template', [
                'template_id' => $templateId,
                'status' => $template?->status,
            ]);

            return false;
        }

        $template->update(['status' => 'active']);

        return true;
    }

    /**
     * Get all milestones for a calendar template
     */
    public function getMilestones(string $tenantId, string $templateId): array
    {
        return CalendarMilestone::where('tenant_id', $tenantId)
            ->where('calendar_template_id', $templateId)
            ->orderBy('offset_days')
            ->get()
            ->toArray();
    }

    /**
     * Add a milestone to a calendar template
     */
    public function addMilestone(string $tenantId, string $templateId, array $data): ?CalendarMilestone
    {
        $template = $this->getTemplate($tenantId, $templateId);

        if (! $template) {
            return null;
        }

        return CalendarMilestone::create([
            'tenant_id' => $tenantId,
            'calendar_template_id' => $templateId,
            'name' => $data['name'],
            'offset_days' => $data['offset_days'] ?? 0,
            'milestone_type' => $data['milestone_type'],
            'is_critical' => $data['is_critical'] ?? false,
        ]);
    }

    /**
     * Add a calendar override
     */
    public function addOverride(string $tenantId, string $templateId, string $overrideType, string $overrideDate, string $createdBy, ?string $reason = null): ?CalendarOverride
    {
        $template = $this->getTemplate($tenantId, $templateId);

        if (! $template) {
            return null;
        }

        return CalendarOverride::create([
            'tenant_id' => $tenantId,
            'calendar_template_id' => $templateId,
            'override_type' => $overrideType,
            'override_date' => $overrideDate,
            'reason' => $reason,
            'created_by' => $createdBy,
        ]);
    }
}
