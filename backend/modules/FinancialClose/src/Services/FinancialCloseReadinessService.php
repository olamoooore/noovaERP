<?php

namespace Modules\FinancialClose\Services;

use Modules\FinancialClose\Events\FinancialCloseReadinessSnapshotGenerated;
use Modules\FinancialClose\Jobs\RefreshReadinessSnapshotJob;
use Modules\FinancialClose\Models\AccountReview;
use Modules\FinancialClose\Models\AiFinding;
use Modules\FinancialClose\Models\CloseReadinessSnapshot;
use Modules\FinancialClose\Models\CloseTask;

class FinancialCloseReadinessService
{
    private const STATUS_WEIGHTS = [
        'locked' => 100,
        'approved' => 100,
        'submitted' => 50,
        'in_progress' => 20,
    ];

    /**
     * Generate a readiness snapshot for an entity/period.
     * Blends account review status with task completion metrics.
     */
    public function generateSnapshot(string $tenantId, string $entityId, string $periodId, ?string $cycleId = null): ?CloseReadinessSnapshot
    {
        // --- Account reviews ---
        $reviews = AccountReview::where('tenant_id', $tenantId)
            ->where('entity_id', $entityId)
            ->get();

        $totalAccounts = $reviews->count();
        $reviewedAccounts = $reviews->whereIn('status', ['submitted', 'approved', 'locked'])->count();
        $signedOffAccounts = $reviews->whereIn('status', ['approved', 'locked'])->count();

        $sectionBreakdown = $this->buildSectionBreakdown($reviews);

        $weightedSum = array_sum(array_column($sectionBreakdown, 'score'));
        $accountScore = $totalAccounts > 0 ? round($weightedSum / $totalAccounts, 2) : null;

        // --- AI findings (entity-wide) ---
        $openFindings = AiFinding::where('tenant_id', $tenantId)
            ->where('entity_id', $entityId)
            ->where('status', 'open')
            ->count();

        // --- Tasks ---
        $taskQuery = CloseTask::where('tenant_id', $tenantId);
        if ($cycleId) {
            $taskQuery->where('cycle_id', $cycleId);
        }

        $totalTasks = (clone $taskQuery)->count();
        $completedTasks = (clone $taskQuery)->where('status', 'completed')->count();
        $blockedTasks = (clone $taskQuery)->whereIn('status', ['blocked', 'escalated'])->count();
        $cancelledTasks = (clone $taskQuery)->where('status', 'cancelled')->count();
        $inProgressTasks = (clone $taskQuery)->where('status', 'in_progress')->count();
        $submittedTasks = (clone $taskQuery)->where('status', 'submitted')->count();
        $createdTasks = (clone $taskQuery)->where('status', 'created')->count();

        $activeTasks = $totalTasks - $completedTasks - $cancelledTasks;
        $effectiveTotal = $totalTasks - $cancelledTasks;
        $taskCompletionRate = $effectiveTotal > 0 ? round(($completedTasks / $effectiveTotal) * 100, 2) : 0;

        // Penalize blocked/escalated tasks (each blocked task reduces score by up to 10%)
        $blockedPenalty = $effectiveTotal > 0 ? min(30, round(($blockedTasks / $effectiveTotal) * 100, 2)) : 0;
        $taskCompletionRate = max(0, round($taskCompletionRate - $blockedPenalty, 2));

        // --- Blend score ---
        if ($accountScore !== null) {
            $readinessScore = round(0.5 * $accountScore + 0.5 * $taskCompletionRate, 2);
        } else {
            $readinessScore = $taskCompletionRate;
        }

        $snapshot = CloseReadinessSnapshot::create([
            'tenant_id' => $tenantId,
            'entity_id' => $entityId,
            'period_id' => $periodId,
            'readiness_score' => $readinessScore,
            'total_accounts' => $totalAccounts,
            'reviewed_accounts' => $reviewedAccounts,
            'signed_off_accounts' => $signedOffAccounts,
            'open_blockers' => $blockedTasks,
            'open_findings' => $openFindings,
            'section_breakdown' => $sectionBreakdown,
            'generated_at' => now(),
            'metadata' => [
                'total_tasks' => $totalTasks,
                'completed_tasks' => $completedTasks,
                'blocked_tasks' => $blockedTasks,
                'cancelled_tasks' => $cancelledTasks,
                'in_progress_tasks' => $inProgressTasks,
                'submitted_tasks' => $submittedTasks,
                'created_tasks' => $createdTasks,
                'active_tasks' => $activeTasks,
                'task_completion_rate' => $taskCompletionRate,
                'account_score' => $accountScore,
            ],
        ]);

        event(new FinancialCloseReadinessSnapshotGenerated($tenantId, $entityId, $periodId, $readinessScore, $blockedTasks));

        return $snapshot;
    }

    /**
     * Get the latest readiness snapshot for an entity/period
     */
    public function getLatestSnapshot(string $tenantId, string $entityId, string $periodId): ?CloseReadinessSnapshot
    {
        return CloseReadinessSnapshot::where('tenant_id', $tenantId)
            ->where('entity_id', $entityId)
            ->where('period_id', $periodId)
            ->orderBy('generated_at', 'desc')
            ->first();
    }

    /**
     * Get a readiness summary — fully live-computed from current data.
     * Auto-generates a snapshot if none exists for this entity/period.
     */
    public function getReadinessSummary(string $tenantId, string $entityId, string $periodId, ?string $cycleId = null): array
    {
        $snapshot = $this->getLatestSnapshot($tenantId, $entityId, $periodId);

        if (! $snapshot) {
            $snapshot = $this->generateSnapshot($tenantId, $entityId, $periodId, $cycleId);
        }

        if (! $snapshot) {
            return [
                'total_accounts' => 0,
                'reviewed_accounts' => 0,
                'signed_off_accounts' => 0,
                'open_blockers' => 0,
                'open_findings' => 0,
                'readiness_score' => 0,
                'is_ready' => false,
                'snapshot_available' => false,
                'generated_at' => null,
                'accounts' => [],
            ];
        }

        $reviews = AccountReview::where('tenant_id', $tenantId)
            ->where('entity_id', $entityId)
            ->get();

        $totalAccounts = $reviews->count();
        $signedOffAccounts = $reviews->whereIn('status', ['approved', 'locked'])->count();

        $sectionBreakdown = $this->buildSectionBreakdown($reviews);

        $weightedSum = array_sum(array_column($sectionBreakdown, 'score'));
        $accountScore = $totalAccounts > 0 ? round($weightedSum / $totalAccounts, 2) : null;

        $taskQuery = CloseTask::where('tenant_id', $tenantId);
        if ($cycleId) {
            $taskQuery->where('cycle_id', $cycleId);
        }

        $totalTasks = (clone $taskQuery)->count();
        $completedTasks = (clone $taskQuery)->where('status', 'completed')->count();
        $blockedTasks = (clone $taskQuery)->whereIn('status', ['blocked', 'escalated'])->count();
        $cancelledTasks = (clone $taskQuery)->where('status', 'cancelled')->count();

        $activeTasks = $totalTasks - $completedTasks - $cancelledTasks;
        $effectiveTotal = $totalTasks - $cancelledTasks;
        $taskCompletionRate = $effectiveTotal > 0 ? round(($completedTasks / $effectiveTotal) * 100, 2) : 0;

        $blockedPenalty = $effectiveTotal > 0 ? min(30, round(($blockedTasks / $effectiveTotal) * 100, 2)) : 0;
        $taskCompletionRate = max(0, round($taskCompletionRate - $blockedPenalty, 2));

        if ($accountScore !== null) {
            $readinessScore = round(0.5 * $accountScore + 0.5 * $taskCompletionRate, 2);
        } else {
            $readinessScore = $taskCompletionRate;
        }

        $openFindings = AiFinding::where('tenant_id', $tenantId)
            ->where('entity_id', $entityId)
            ->where('status', 'open')
            ->count();

        return [
            'readiness_score' => $readinessScore,
            'total_accounts' => $totalAccounts,
            'reviewed_accounts' => $reviews->whereIn('status', ['submitted', 'approved', 'locked'])->count(),
            'signed_off_accounts' => $signedOffAccounts,
            'open_blockers' => $blockedTasks,
            'open_findings' => $openFindings,
            'is_ready' => $readinessScore >= 100 && $blockedTasks === 0,
            'snapshot_available' => true,
            'generated_at' => $snapshot->generated_at,
            'accounts' => $sectionBreakdown,
        ];
    }

    /**
     * Dispatch a readiness refresh for a given cycle (sync or async).
     */
    public function dispatchRefreshForCycle(string $tenantId, string $cycleId): void
    {
        $cycle = \Modules\FinancialClose\Models\CloseCycle::where('tenant_id', $tenantId)
            ->where('id', $cycleId)
            ->first();

        if (! $cycle) {
            return;
        }

        RefreshReadinessSnapshotJob::dispatch(
            $tenantId,
            $cycle->entity_id,
            $cycle->period_id,
            $cycleId,
        );
    }

    private function buildSectionBreakdown($reviews): array
    {
        // Defensive guard: with no account reviews there is nothing to break
        // down. The previous `$reviews->first()?->tenant_id ?? ''` fallback
        // queried AiFinding with an empty-string tenant_id against a UUID
        // column, raising SQLSTATE[22P02] and 500-ing the readiness refresh for
        // any entity that has no reviews yet (a normal state at period start).
        // The empty `whereIn('review_id', [])` also degenerated to `0 = 1`.
        if ($reviews->isEmpty()) {
            return [];
        }

        $findingsByReview = AiFinding::where('tenant_id', $reviews->first()->tenant_id)
            ->whereIn('review_id', $reviews->pluck('id'))
            ->where('status', 'open')
            ->get()
            ->groupBy('review_id');

        return $reviews
            ->sortBy('account_code')
            ->values()
            ->map(function (AccountReview $review) use ($findingsByReview): array {
                return [
                    'review_id' => $review->id,
                    'account_code' => $review->account_code,
                    'account_name' => $review->account_name,
                    'status' => $review->status,
                    'risk_status' => $review->risk_status,
                    'score' => self::STATUS_WEIGHTS[$review->status] ?? 0,
                    'open_findings' => $findingsByReview->get($review->id)?->count() ?? 0,
                ];
            })
            ->all();
    }
}
