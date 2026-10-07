<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TaxPeriodClosingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * MRD R7.4 — tax period close/reopen/list over the existing
 * TaxPeriodClosingService (previously orphaned: a correct engine with no
 * route). Closing a period snapshots its summary and locks it: the posting
 * gate then refuses NEW tax-bearing postings dated inside the closed month,
 * forcing amendments through open-period credit/debit documents.
 */
class TaxPeriodController extends Controller
{
    public function __construct(private readonly TaxPeriodClosingService $service) {}

    public function index(): JsonResponse
    {
        $tenantId = tenant('id');

        $closed = $this->service->listClosedPeriods((int) $tenantId);

        return response()->json([
            'data' => array_map(fn (array $row) => [
                'period' => $row['period'] ?? null,
                'closed_at' => $row['closed_at'] ?? null,
                'closed_by' => $row['closed_by'] ?? null,
                'summary' => $row['summary'] ?? null,
            ], $closed),
        ]);
    }

    public function close(\Illuminate\Http\Request $request, string $period): JsonResponse
    {
        // #278 F4: optional lock scope — a legal entity and/or a regime
        // (country code). Omitted = tenant-wide / every regime.
        $scope = $request->validate([
            'entity_id' => 'nullable|integer|exists:legal_entities,id',
            'regime' => 'nullable|string|max:20',
        ]);

        try {
            $summary = $this->service->closePeriod(
                $period,
                (int) tenant('id'),
                isset($scope['entity_id']) ? (int) $scope['entity_id'] : null,
                $scope['regime'] ?? null,
            );
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => "Tax period {$period} closed", 'data' => $summary], 201);
    }

    public function reopen(\Illuminate\Http\Request $request, string $period): JsonResponse
    {
        // #278 F4: reopening a filed period is a governed action — mandatory
        // reason, elevated authority, audit trail and an amendment flag on
        // every filed baseline covering the period.
        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:500',
            'entity_id' => 'nullable|integer|exists:legal_entities,id',
            'regime' => 'nullable|string|max:20',
        ]);
        $entityId = isset($validated['entity_id']) ? (int) $validated['entity_id'] : null;
        $regime = TaxPeriodClosingService::normalizeRegime($validated['regime'] ?? null);

        if (! \App\Http\Middleware\RequirePermission::allows($request, 'tax.policy.manage')) {
            return response()->json([
                'message' => 'Reopening a filed tax period requires tax.policy.manage.',
                'required_permission' => 'tax.policy.manage',
            ], 403);
        }

        try {
            $this->service->reopenPeriod($period, (int) tenant('id'), $entityId, $regime);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $months = \Modules\AccountingCountry\Services\TaxReportingService::expandFilingPeriod($period) ?: [$period];
        $flagged = 0;

        // A scoped reopen only invalidates the filed baselines of that scope.
        $filings = \App\Models\StatutoryReturnFiling::whereNull('superseded_at')
            ->when($entityId !== null, fn ($q) => $q->where('entity_key', $entityId))
            ->when($regime !== null, fn ($q) => $q->where('country', $regime))
            ->get();

        foreach ($filings as $filing) {
            $filingMonths = \Modules\AccountingCountry\Services\TaxReportingService::expandFilingPeriod((string) $filing->period, (string) $filing->country) ?: [(string) $filing->period];

            if (array_intersect($months, $filingMonths)) {
                $filing->update(['state' => 'AMENDMENT_REQUIRED', 'state_updated_at' => now()]);
                $flagged++;
            }
        }

        \App\Models\AuditLog::create([
            'operation' => 'tax_period_reopened',
            'reference_type' => 'tax_period',
            'reference_id' => 0,
            'actor_id' => $request->user()?->id,
            'source_route' => $request->path(),
            'before_state' => ['period' => $period, 'legal_entity_id' => $entityId, 'regime' => $regime, 'locked' => true],
            'after_state' => ['period' => $period, 'legal_entity_id' => $entityId, 'regime' => $regime, 'locked' => false],
            'metadata' => ['reason' => $validated['reason'], 'filings_flagged' => $flagged],
        ]);

        app(\App\Services\TaxOutboxPublisher::class)->publish(
            'TaxPeriodReopened',
            $period,
            1,
            ['period' => $period, 'legal_entity_id' => $entityId, 'regime' => $regime, 'reason' => $validated['reason'], 'filings_flagged' => $flagged],
        );

        return response()->json([
            'message' => "Tax period {$period} reopened",
            'filings_flagged_for_amendment' => $flagged,
        ]);
    }

    /**
     * Lock states for the calendar months of a period range — powers the
     * Tax Report UI banner.
     */
    public function lockStates(): JsonResponse
    {
        $rows = DB::table('tax_period_locks')
            ->where('tenant_id', (int) tenant('id'))
            ->pluck('closed_at', 'period');

        return response()->json([
            'data' => $rows->map(fn ($closedAt, $period) => [
                'period' => $period,
                'locked' => true,
                'closed_at' => (string) $closedAt,
            ])->values(),
        ]);
    }
}
