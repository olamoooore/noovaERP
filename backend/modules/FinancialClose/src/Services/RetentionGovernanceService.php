<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Models\AuditLog;
use Modules\FinancialClose\Models\AccessDecisionLog;
use Modules\FinancialClose\Models\AiInferenceLog;
use Modules\FinancialClose\Models\RestrictedDocumentAccessLog;
use Modules\FinancialClose\Models\CloseReadinessSnapshot;
use Modules\FinancialClose\Models\JobRunHistory;

class RetentionGovernanceService
{
    /**
     * Archive old audit logs beyond retention period
     */
    public function archiveAuditLogs(string $tenantId, int $retentionDays = 365): int
    {
        $cutoff = now()->subDays($retentionDays);

        $count = AuditLog::where('tenant_id', $tenantId)
            ->where('created_at', '<', $cutoff)
            ->count();

        // In production this would move records to archive storage
        // For now we log the count of records that would be archived
        Log::info('FinancialClose: audit log retention scan', [
            'tenant_id' => $tenantId,
            'records_eligible_for_archive' => $count,
            'cutoff_date' => $cutoff,
        ]);

        return $count;
    }

    /**
     * Purge old access decision logs
     */
    public function purgeAccessLogs(string $tenantId, int $retentionDays = 90): int
    {
        $cutoff = now()->subDays($retentionDays);

        $count = AccessDecisionLog::where('tenant_id', $tenantId)
            ->where('created_at', '<', $cutoff)
            ->delete();

        Log::info('FinancialClose: access log purge complete', [
            'tenant_id' => $tenantId,
            'records_purged' => $count,
        ]);

        return $count;
    }

    /**
     * Purge old AI inference logs
     */
    public function purgeAiInferenceLogs(string $tenantId, int $retentionDays = 90): int
    {
        $cutoff = now()->subDays($retentionDays);

        $count = AiInferenceLog::where('tenant_id', $tenantId)
            ->where('created_at', '<', $cutoff)
            ->delete();

        Log::info('FinancialClose: AI inference log purge complete', [
            'tenant_id' => $tenantId,
            'records_purged' => $count,
        ]);

        return $count;
    }

    /**
     * Purge old restricted document access logs
     */
    public function purgeDocumentAccessLogs(string $tenantId, int $retentionDays = 90): int
    {
        $cutoff = now()->subDays($retentionDays);

        $count = RestrictedDocumentAccessLog::where('tenant_id', $tenantId)
            ->where('created_at', '<', $cutoff)
            ->delete();

        Log::info('FinancialClose: document access log purge complete', [
            'tenant_id' => $tenantId,
            'records_purged' => $count,
        ]);

        return $count;
    }

    /**
     * Purge stale readiness snapshots
     */
    public function purgeStaleSnapshots(string $tenantId, int $retentionDays = 30): int
    {
        $cutoff = now()->subDays($retentionDays);

        $count = CloseReadinessSnapshot::where('tenant_id', $tenantId)
            ->where('generated_at', '<', $cutoff)
            ->delete();

        Log::info('FinancialClose: stale readiness snapshots purged', [
            'tenant_id' => $tenantId,
            'records_purged' => $count,
        ]);

        return $count;
    }

    /**
     * Purge old job run history
     */
    public function purgeJobRunHistory(string $tenantId, int $retentionDays = 30): int
    {
        $cutoff = now()->subDays($retentionDays);

        $count = JobRunHistory::where('tenant_id', $tenantId)
            ->where('created_at', '<', $cutoff)
            ->delete();

        Log::info('FinancialClose: job run history purged', [
            'tenant_id' => $tenantId,
            'records_purged' => $count,
        ]);

        return $count;
    }
}