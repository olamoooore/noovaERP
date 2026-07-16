<?php

namespace Modules\EventManagement\Console\Commands;

use Illuminate\Console\Command;
use Modules\EventManagement\Services\DataRetentionService;

class CleanupAuditLogsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'event-management:cleanup-audit-logs 
                            {--tenant= : Specific tenant ID to cleanup (optional)}
                            {--retention-days= : Override retention days (optional)}
                            {--immediate : Perform immediate cleanup instead of queuing jobs}
                            {--dry-run : Show what would be cleaned up without actually deleting}';

    /**
     * The console command description.
     */
    protected $description = 'Clean up old audit logs based on tenant retention policies';

    protected DataRetentionService $dataRetentionService;

    public function __construct(DataRetentionService $dataRetentionService)
    {
        parent::__construct();
        $this->dataRetentionService = $dataRetentionService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $retentionDays = $this->option('retention-days') ? (int) $this->option('retention-days') : null;
        $immediate = $this->option('immediate');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            return $this->handleDryRun($tenantId);
        }

        try {
            if ($tenantId) {
                // Clean up specific tenant
                $this->info("Processing audit log cleanup for tenant: {$tenantId}");

                if ($immediate) {
                    $deletedCount = $this->dataRetentionService->performImmediateCleanup($tenantId, $retentionDays);
                    $this->info("Deleted {$deletedCount} audit log entries for tenant {$tenantId}");
                } else {
                    $this->dataRetentionService->scheduleCleanupForTenant($tenantId, $retentionDays);
                    $this->info("Scheduled audit log cleanup job for tenant {$tenantId}");
                }
            } else {
                // Clean up all tenants
                $this->info('Processing audit log cleanup for all tenants');

                if ($immediate) {
                    $this->error('Immediate cleanup for all tenants is not supported. Use --tenant option or remove --immediate flag.');

                    return 1;
                } else {
                    $this->dataRetentionService->scheduleCleanupForAllTenants();
                    $this->info('Scheduled audit log cleanup jobs for all tenants');
                }
            }

            $this->info('Audit log cleanup completed successfully');

            return 0;

        } catch (\Exception $e) {
            $this->error('Audit log cleanup failed: '.$e->getMessage());

            return 1;
        }
    }

    /**
     * Handle dry run mode
     */
    protected function handleDryRun(?string $tenantId = null): int
    {
        $this->info('DRY RUN MODE - No data will be deleted');
        $this->line('');

        try {
            if ($tenantId) {
                $stats = $this->dataRetentionService->getAuditLogStatistics($tenantId);
                $this->displayTenantStatistics($stats);
            } else {
                $this->info('Getting statistics for all tenants...');
                // This would require getting all tenant IDs - simplified for now
                $this->warn('Dry run for all tenants not implemented. Use --tenant option.');

                return 1;
            }

            return 0;

        } catch (\Exception $e) {
            $this->error('Failed to get audit log statistics: '.$e->getMessage());

            return 1;
        }
    }

    /**
     * Display statistics for a tenant
     */
    protected function displayTenantStatistics(array $stats): void
    {
        $this->info("Audit Log Statistics for Tenant: {$stats['tenant_id']}");
        $this->line("Retention Policy: {$stats['retention_days']} days");
        $this->line("Total Logs: {$stats['total_logs']}");
        $this->line("Expired Logs (would be deleted): {$stats['expired_logs']}");
        $this->line("Active Logs (would be kept): {$stats['active_logs']}");
        $this->line("Cutoff Date: {$stats['cutoff_date']}");

        if ($stats['oldest_log_date']) {
            $this->line("Oldest Log: {$stats['oldest_log_date']}");
        }

        if ($stats['newest_log_date']) {
            $this->line("Newest Log: {$stats['newest_log_date']}");
        }
    }
}
