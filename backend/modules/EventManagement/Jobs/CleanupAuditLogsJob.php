<?php

namespace Modules\EventManagement\Jobs;

use Modules\EventManagement\Models\EventSettings;
use Modules\EventManagement\Services\AuditService;
use Modules\EventManagement\Services\TenantSchemaManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CleanupAuditLogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $tenantId;
    protected ?int $retentionDays;

    /**
     * Create a new job instance.
     */
    public function __construct(string $tenantId, ?int $retentionDays = null)
    {
        $this->tenantId = $tenantId;
        $this->retentionDays = $retentionDays;
    }

    /**
     * Execute the job.
     */
    public function handle(AuditService $auditService, TenantSchemaManager $tenantSchemaManager): void
    {
        try {
            // Set tenant context
            $tenantSchemaManager->setTenantContext($this->tenantId);

            // Get retention policy from tenant settings or use provided value
            $retentionDays = $this->retentionDays ?? $this->getTenantRetentionPolicy();

            // Clean up old logs
            $deletedCount = $auditService->cleanupOldLogs($this->tenantId, $retentionDays);

            // Log the cleanup activity
            $auditService->logSystemActivity(
                'audit_logs_cleanup',
                'system',
                0, // No specific subject ID for system-wide cleanup
                [
                    'tenant_id' => $this->tenantId,
                    'retention_days' => $retentionDays,
                    'deleted_count' => $deletedCount,
                    'cleanup_date' => now()->toISOString(),
                ]
            );

            Log::info("Audit logs cleanup completed for tenant {$this->tenantId}", [
                'tenant_id' => $this->tenantId,
                'retention_days' => $retentionDays,
                'deleted_count' => $deletedCount,
            ]);

        } catch (\Exception $e) {
            Log::error("Audit logs cleanup failed for tenant {$this->tenantId}", [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to mark job as failed
            throw $e;
        } finally {
            // Reset tenant context
            $tenantSchemaManager->resetToOriginalConnection();
        }
    }

    /**
     * Get tenant-specific retention policy
     */
    protected function getTenantRetentionPolicy(): int
    {
        $settings = EventSettings::where('tenant_id', $this->tenantId)->first();
        
        // Default to 7 years (2555 days) if no specific policy is set
        return $settings ? $settings->data_retention_days : 2555;
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return ['audit-cleanup', "tenant:{$this->tenantId}"];
    }
}