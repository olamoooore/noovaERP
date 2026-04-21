<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Models\CloseCycle;
use Modules\FinancialClose\Models\CloseTask;
use Modules\FinancialClose\Models\CompletionSnapshot;

class FinancialCloseCompletionService
{
    /**
     * Generate a completion snapshot for a cycle
     */
    public function generateSnapshot(string $tenantId, string $cycleId): ?CompletionSnapshot
    {
        $cycle = CloseCycle::where('tenant_id', $tenantId)
            ->where('id', $cycleId)
            ->first();

        if (!$cycle) {
            return null;
        }

        $tasks = CloseTask::where('tenant_id', $tenantId)
            ->where('cycle_id', $cycleId)
            ->get();

        $total = $tasks->count();
        $completed = $tasks->where('status', 'completed')->count();
        $blocked = $tasks->where('status', 'blocked')->count();
        $overdue = $tasks->where('status', '!=', 'completed')
            ->where('due_date', '<', now())
            ->count();

        $percentage = $total > 0 ? round(($completed / $total) * 100, 2) : 0;

        return CompletionSnapshot::create([
            'tenant_id' => $tenantId,
            'cycle_id' => $cycleId,
            'total_tasks' => $total,
            'completed_tasks' => $completed,
            'blocked_tasks' => $blocked,
            'overdue_tasks' => $overdue,
            'completion_percentage' => $percentage,
            'generated_at' => now(),
        ]);
    }

    /**
     * Get the latest completion snapshot for a cycle
     */
    public function getLatestSnapshot(string $tenantId, string $cycleId): ?CompletionSnapshot
    {
        return CompletionSnapshot::where('tenant_id', $tenantId)
            ->where('cycle_id', $cycleId)
            ->orderBy('generated_at', 'desc')
            ->first();
    }

    /**
     * Get completion progress summary for a cycle
     */
    public function getProgressSummary(string $tenantId, string $cycleId): array
    {
        $tasks = CloseTask::where('tenant_id', $tenantId)
            ->where('cycle_id', $cycleId)
            ->get();

        $total = $tasks->count();
        $completed = $tasks->where('status', 'completed')->count();

        return [
            'total_tasks' => $total,
            'completed_tasks' => $completed,
            'blocked_tasks' => $tasks->where('status', 'blocked')->count(),
            'overdue_tasks' => $tasks->where('status', '!=', 'completed')
                ->where('due_date', '<', now())
                ->count(),
            'completion_percentage' => $total > 0 ? round(($completed / $total) * 100, 2) : 0,
            'is_ready_to_close' => $total > 0 && $completed === $total,
        ];
    }
}