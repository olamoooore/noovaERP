<?php

namespace Modules\FinancialClose\Services;

use Modules\FinancialClose\Models\ChecklistTemplate;
use Modules\FinancialClose\Models\TemplateApproval;
use Modules\FinancialClose\Models\TemplateChangeAudit;
use Modules\FinancialClose\Models\TemplateVersion;

class ChecklistGovernanceService
{
    /**
     * Create a new version of a checklist template
     * Active cycles reference template snapshots -- not live templates
     */
    public function createVersion(string $tenantId, string $templateId, string $userId): ?TemplateVersion
    {
        $template = ChecklistTemplate::where('tenant_id', $tenantId)
            ->where('id', $templateId)
            ->first();

        if (! $template) {
            return null;
        }

        $latestVersion = TemplateVersion::where('tenant_id', $tenantId)
            ->where('checklist_template_id', $templateId)
            ->orderBy('version_number', 'desc')
            ->first();

        $versionNumber = $latestVersion ? $latestVersion->version_number + 1 : 1;

        // Snapshot the current template state
        $snapshot = $template->toArray();

        return TemplateVersion::create([
            'tenant_id' => $tenantId,
            'checklist_template_id' => $templateId,
            'version_number' => $versionNumber,
            'status' => 'draft',
            'snapshot' => $snapshot,
            'created_by' => $userId,
        ]);
    }

    /**
     * Submit a template version for approval
     */
    public function submitVersion(string $tenantId, string $versionId, string $userId): bool
    {
        $version = TemplateVersion::where('tenant_id', $tenantId)
            ->where('id', $versionId)
            ->first();

        if (! $version || $version->status !== 'draft') {
            return false;
        }

        $version->update(['status' => 'pending_approval']);

        TemplateApproval::create([
            'tenant_id' => $tenantId,
            'template_version_id' => $versionId,
            'approved_by' => $userId,
            'status' => 'pending',
        ]);

        return true;
    }

    /**
     * Approve a template version
     */
    public function approveVersion(string $tenantId, string $versionId, string $userId, ?string $notes = null): bool
    {
        $version = TemplateVersion::where('tenant_id', $tenantId)
            ->where('id', $versionId)
            ->first();

        if (! $version || $version->status !== 'pending_approval') {
            return false;
        }

        $version->update(['status' => 'approved']);

        TemplateApproval::where('tenant_id', $tenantId)
            ->where('template_version_id', $versionId)
            ->where('status', 'pending')
            ->update([
                'status' => 'approved',
                'notes' => $notes,
                'decided_at' => now(),
            ]);

        // Write change audit
        TemplateChangeAudit::create([
            'tenant_id' => $tenantId,
            'template_version_id' => $versionId,
            'changed_by' => $userId,
            'change_type' => 'approved',
            'after' => ['status' => 'approved'],
        ]);

        return true;
    }

    /**
     * Get all versions for a template
     */
    public function listVersions(string $tenantId, string $templateId): array
    {
        return TemplateVersion::where('tenant_id', $tenantId)
            ->where('checklist_template_id', $templateId)
            ->orderBy('version_number', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Get the latest approved snapshot for a template
     */
    public function getLatestApprovedSnapshot(string $tenantId, string $templateId): ?array
    {
        $version = TemplateVersion::where('tenant_id', $tenantId)
            ->where('checklist_template_id', $templateId)
            ->where('status', 'approved')
            ->orderBy('version_number', 'desc')
            ->first();

        return $version?->snapshot;
    }
}
