<?php

namespace Modules\EventManagement\Services;

use Modules\EventManagement\Jobs\CleanupAuditLogsJob;
use Modules\EventManagement\Models\EventSettings;
use Illuminate\Support\Facades\Log;

class DataRetentionService
{
    protected AuditService $auditService;
    protected TenantSchemaManager $tenantSchemaManager;

    public function __construct(AuditService $auditService, TenantSchemaManager $tenantSchemaManager)
    {
        $this->auditService = $auditService;
        $this->tenantSchemaManager = $tenantSchemaManager;
    }

    /**
     * Schedule cleanup for a specific tenant
     */
    public function scheduleCleanupForTenant(string $tenantId, ?int $retentionDays = null): void
    {
        CleanupAuditLogsJob::dispatch($tenantId, $retentionDays);
        
        Log::info("Scheduled audit logs cleanup for tenant {$tenantId}", [
            'tenant_id' => $tenantId,
            'retention_days' => $retentionDays,
        ]);
    }

    /**
     * Schedule cleanup for all tenants with event management enabled
     */
    public function scheduleCleanupForAllTenants(): void
    {
        // Get all tenants with event settings (indicating they have event management enabled)
        $tenantSettings = EventSettings::all();

        foreach ($tenantSettings as $settings) {
            $this->scheduleCleanupForTenant($settings->tenant_id, $settings->data_retention_days);
        }

        Log::info("Scheduled audit logs cleanup for all tenants", [
            'tenant_count' => $tenantSettings->count(),
        ]);
    }

    /**
     * Perform immediate cleanup for a specific tenant
     */
    public function performImmediateCleanup(string $tenantId, ?int $retentionDays = null): int
    {
        try {
            // Set tenant context
            $this->tenantSchemaManager->setTenantContext($tenantId);

            // Get retention policy
            $retentionDays = $retentionDays ?? $this->getTenantRetentionPolicy($tenantId);

            // Perform cleanup
            $deletedCount = $this->auditService->cleanupOldLogs($tenantId, $retentionDays);

            // Log the cleanup activity
            $this->auditService->logSystemActivity(
                'audit_logs_immediate_cleanup',
                'system',
                0,
                [
                    'tenant_id' => $tenantId,
                    'retention_days' => $retentionDays,
                    'deleted_count' => $deletedCount,
                    'cleanup_date' => now()->toISOString(),
                ]
            );

            Log::info("Immediate audit logs cleanup completed for tenant {$tenantId}", [
                'tenant_id' => $tenantId,
                'retention_days' => $retentionDays,
                'deleted_count' => $deletedCount,
            ]);

            return $deletedCount;

        } finally {
            // Reset tenant context
            $this->tenantSchemaManager->resetToOriginalConnection();
        }
    }

    /**
     * Update retention policy for a tenant
     */
    public function updateRetentionPolicy(string $tenantId, int $retentionDays): void
    {
        try {
            // Set tenant context
            $this->tenantSchemaManager->setTenantContext($tenantId);

            // Update or create event settings
            EventSettings::updateOrCreate(
                ['tenant_id' => $tenantId],
                ['data_retention_days' => $retentionDays]
            );

            // Log the policy change
            $this->auditService->logSystemActivity(
                'retention_policy_updated',
                'event_settings',
                0,
                [
                    'tenant_id' => $tenantId,
                    'new_retention_days' => $retentionDays,
                    'updated_at' => now()->toISOString(),
                ]
            );

            Log::info("Updated retention policy for tenant {$tenantId}", [
                'tenant_id' => $tenantId,
                'retention_days' => $retentionDays,
            ]);

        } finally {
            // Reset tenant context
            $this->tenantSchemaManager->resetToOriginalConnection();
        }
    }

    /**
     * Get retention policy for a tenant
     */
    public function getRetentionPolicy(string $tenantId): int
    {
        try {
            // Set tenant context
            $this->tenantSchemaManager->setTenantContext($tenantId);
            
            return $this->getTenantRetentionPolicy($tenantId);
            
        } finally {
            // Reset tenant context
            $this->tenantSchemaManager->resetToOriginalConnection();
        }
    }

    /**
     * Get tenant-specific retention policy
     */
    protected function getTenantRetentionPolicy(string $tenantId): int
    {
        $settings = EventSettings::where('tenant_id', $tenantId)->first();
        
        // Default to 7 years (2555 days) if no specific policy is set
        return $settings ? $settings->data_retention_days : 2555;
    }

    /**
     * Get statistics about audit logs for a tenant
     */
    public function getAuditLogStatistics(string $tenantId): array
    {
        try {
            // Set tenant context
            $this->tenantSchemaManager->setTenantContext($tenantId);

            $retentionDays = $this->getTenantRetentionPolicy($tenantId);
            $cutoffDate = now()->subDays($retentionDays);

            // Get total count and count of logs that would be cleaned up
            $totalLogs = \DB::table('activity_logs')
                ->where('tenant_id', $tenantId)
                ->count();

            $expiredLogs = \DB::table('activity_logs')
                ->where('tenant_id', $tenantId)
                ->where('created_at', '<', $cutoffDate)
                ->count();

            $oldestLog = \DB::table('activity_logs')
                ->where('tenant_id', $tenantId)
                ->orderBy('created_at', 'asc')
                ->first();

            $newestLog = \DB::table('activity_logs')
                ->where('tenant_id', $tenantId)
                ->orderBy('created_at', 'desc')
                ->first();

            return [
                'tenant_id' => $tenantId,
                'retention_days' => $retentionDays,
                'total_logs' => $totalLogs,
                'expired_logs' => $expiredLogs,
                'active_logs' => $totalLogs - $expiredLogs,
                'oldest_log_date' => $oldestLog ? $oldestLog->created_at : null,
                'newest_log_date' => $newestLog ? $newestLog->created_at : null,
                'cutoff_date' => $cutoffDate->toISOString(),
            ];

        } finally {
            // Reset tenant context
            $this->tenantSchemaManager->resetToOriginalConnection();
        }
    }
}