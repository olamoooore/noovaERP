<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Models\CloseReadinessSnapshot;
use Modules\FinancialClose\Models\CloseCycle;
use Modules\FinancialClose\Models\CloseTask;

class FinancialCloseForecastService
{
    /**
     * Generate a close completion forecast based on current progress
     * Forecast is advisory only -- not close truth
     */
    public function generateForecast(string $tenantId, string $cycleId): array
    {
        $cycle = CloseCycle::where('tenant_id', $tenantId)
            ->where('id', $cycleId)
            ->first();

        if (!$cycle) {
            return [
                'forecast_available' => false,
            ];
        }

        $tasks = CloseTask::where('tenant_id', $tenantId)
            ->where('cycle_id', $cycleId)
            ->get();

        $total = $tasks->count();
        $completed = $tasks->where('status', 'completed')->count();
        $overdue = $tasks->where('status', '!=', 'completed')
            ->where('due_date', '<', now())
            ->count();
        $blocked = $tasks->where('status', 'blocked')->count();

        $completionRate = $total > 0 ? round(($completed / $total) * 100, 2) : 0;

        $riskLevel = 'low';
        if ($overdue > 0 || $blocked > 0) {
            $riskLevel = 'medium';
        }
        if ($overdue > 3 || $blocked > 2) {
            $riskLevel = 'high';
        }

        return [
            'forecast_available' => true,
            'cycle_id' => $cycleId,
            'completion_rate' => $completionRate,
            'total_tasks' => $total,
            'completed_tasks' => $completed,
            'overdue_tasks' => $overdue,
            'blocked_tasks' => $blocked,
            'risk_level' => $riskLevel,
            'generated_at' => now(),
        ];
    }

    /**
     * Get historical readiness snapshots for trend analysis
     */
    public function getReadinessTrend(string $tenantId, string $entityId, string $periodId, int $limit = 10): array
    {
        return CloseReadinessSnapshot::where('tenant_id', $tenantId)
            ->where('entity_id', $entityId)
            ->where('period_id', $periodId)
            ->orderBy('generated_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Generate an executive summary projection
     */
    public function getExecutiveSummary(string $tenantId, string $entityId, string $periodId): array
    {
        $latestSnapshot = CloseReadinessSnapshot::where('tenant_id', $tenantId)
            ->where('entity_id', $entityId)
            ->where('period_id', $periodId)
            ->orderBy('generated_at', 'desc')
            ->first();

        if (!$latestSnapshot) {
            return [
                'summary_available' => false,
            ];
        }

        return [
            'summary_available' => true,
            'entity_id' => $entityId,
            'period_id' => $periodId,
            'readiness_score' => $latestSnapshot->readiness_score,
            'open_blockers' => $latestSnapshot->open_blockers,
            'open_findings' => $latestSnapshot->open_findings,
            'accounts_signed_off' => $latestSnapshot->signed_off_accounts,
            'total_accounts' => $latestSnapshot->total_accounts,
            'is_ready_to_close' => $latestSnapshot->readiness_score >= 100 && $latestSnapshot->open_blockers === 0,
            'generated_at' => $latestSnapshot->generated_at,
        ];
    }
}