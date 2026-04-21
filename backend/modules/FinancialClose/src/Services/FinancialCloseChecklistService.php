<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Models\ChecklistTemplate;
use Modules\FinancialClose\Models\CloseTask;
use Modules\FinancialClose\Models\CloseCycle;

class FinancialCloseChecklistService
{
    /**
     * Get all checklist templates for a tenant
     */
    public function listTemplates(string $tenantId, array $filters = []): array
    {
        $query = ChecklistTemplate::where('tenant_id', $tenantId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderBy('created_at', 'desc')->get()->toArray();
    }

    /**
     * Get a single checklist template
     */
    public function getTemplate(string $tenantId, string $templateId): ?ChecklistTemplate
    {
        return ChecklistTemplate::where('tenant_id', $tenantId)
            ->where('id', $templateId)
            ->first();
    }

    /**
     * Create a checklist template
     */
    public function createTemplate(string $tenantId, string $userId, array $data): ?ChecklistTemplate
    {
        return ChecklistTemplate::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'status' => 'draft',
            'created_by' => $userId,
            'task_definitions' => $data['task_definitions'] ?? [],
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    /**
     * Expand a checklist template into tasks for a close cycle
     */
    public function expandTemplateIntoTasks(string $tenantId, string $cycleId, string $templateId): bool
    {
        $cycle = CloseCycle::where('tenant_id', $tenantId)
            ->where('id', $cycleId)
            ->first();

        if (!$cycle) {
            return false;
        }

        $template = $this->getTemplate($tenantId, $templateId);

        if (!$template || $template->status !== 'active') {
            Log::warning('FinancialClose: template not active, cannot expand', [
                'template_id' => $templateId,
            ]);
            return false;
        }

        $taskDefinitions = $template->task_definitions ?? [];

        foreach ($taskDefinitions as $definition) {
            CloseTask::create([
                'tenant_id' => $tenantId,
                'cycle_id' => $cycleId,
                'title' => $definition['title'],
                'status' => 'created',
                'priority' => $definition['priority'] ?? 'medium',
                'is_critical' => $definition['is_critical'] ?? false,
            ]);
        }

        return true;
    }
}