<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Models\CloseReadinessSnapshot;
use Modules\FinancialClose\Models\AccountReview;
use Modules\FinancialClose\Models\AiFinding;
use Modules\FinancialClose\Models\CloseTask;
use Modules\FinancialClose\Events\FinancialCloseReadinessSnapshotGenerated;

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
     * Readiness is advisory only — not close truth.
     */
    public function generateSnapshot(string $tenantId, string $entityId, string $periodId, ?string $cycleId = null): ?CloseReadinessSnapshot
    {
        $reviews = AccountReview::where('tenant_id', $tenantId)
            ->where('entity_id', $entityId)
            ->get();

        $totalAccounts    = $reviews->count();
        $reviewedAccounts = $reviews->whereIn('status', ['submitted', 'approved', 'locked'])->count();
        $signedOffAccounts = $reviews->whereIn('status', ['approved', 'locked'])->count();

        $findingsByReview = AiFinding::where('tenant_id', $tenantId)
            ->whereIn('review_id', $reviews->pluck('id'))
            ->where('status', 'open')
            ->get()
            ->groupBy('review_id');

        $openFindings = $findingsByReview->flatten(1)->count();

        // Open critical tasks from the cycle if provided; otherwise tenant-wide
        $criticalTaskQuery = CloseTask::where('tenant_id', $tenantId)
            ->where('is_critical', true)
            ->whereNotIn('status', ['completed', 'cancelled']);

        if ($cycleId) {
            $criticalTaskQuery->where('cycle_id', $cycleId);
        }

        $openBlockers = $criticalTaskQuery->count();

        $sectionBreakdown = $this->buildSectionBreakdown($reviews, $findingsByReview);

        $weightedSum = array_sum(array_column($sectionBreakdown, 'score'));
        $readinessScore = $totalAccounts > 0
            ? round($weightedSum / $totalAccounts, 2)
            : 0;

        $snapshot = CloseReadinessSnapshot::create([
            'tenant_id'          => $tenantId,
            'entity_id'          => $entityId,
            'period_id'          => $periodId,
            'readiness_score'    => $readinessScore,
            'total_accounts'     => $totalAccounts,
            'reviewed_accounts'  => $reviewedAccounts,
            'signed_off_accounts' => $signedOffAccounts,
            'open_blockers'      => $openBlockers,
            'open_findings'      => $openFindings,
            'section_breakdown'  => $sectionBreakdown,
            'generated_at'       => now(),
        ]);

        event(new FinancialCloseReadinessSnapshotGenerated($tenantId, $entityId, $periodId, $readinessScore, $openBlockers));

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
     * Get a readiness summary — advisory only.
     * is_ready requires: 100% score AND zero open critical blockers.
     */
    public function getReadinessSummary(string $tenantId, string $entityId, string $periodId): array
    {
        $snapshot = $this->getLatestSnapshot($tenantId, $entityId, $periodId);

        if (!$snapshot) {
            return [
                'total_accounts'      => 0,
                'reviewed_accounts'   => 0,
                'signed_off_accounts' => 0,
                'open_blockers'       => 0,
                'open_findings'       => 0,
                'readiness_score'    => 0,
                'is_ready'           => false,
                'snapshot_available' => false,
                'generated_at'       => null,
                'accounts'           => [],
            ];
        }

        $reviews = AccountReview::where('tenant_id', $tenantId)
            ->where('entity_id', $entityId)
            ->get();

        $findingsByReview = AiFinding::where('tenant_id', $tenantId)
            ->whereIn('review_id', $reviews->pluck('id'))
            ->where('status', 'open')
            ->get()
            ->groupBy('review_id');

        return [
            'readiness_score'     => $snapshot->readiness_score,
            'total_accounts'      => $snapshot->total_accounts,
            'reviewed_accounts'   => $snapshot->reviewed_accounts,
            'signed_off_accounts' => $snapshot->signed_off_accounts,
            'open_blockers'       => $snapshot->open_blockers,
            'open_findings'       => $snapshot->open_findings,
            // is_ready requires full score AND zero open critical blockers
            'is_ready'            => $snapshot->readiness_score >= 100
                                     && $snapshot->open_blockers === 0,
            'snapshot_available'  => true,
            'generated_at'        => $snapshot->generated_at,
            'accounts'            => $this->buildSectionBreakdown($reviews, $findingsByReview),
        ];
    }

    private function buildSectionBreakdown($reviews, $findingsByReview): array
    {
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
