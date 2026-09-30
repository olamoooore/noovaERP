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

    public function close(string $period): JsonResponse
    {
        try {
            $summary = $this->service->closePeriod($period, (int) tenant('id'));
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => "Tax period {$period} closed", 'data' => $summary], 201);
    }

    public function reopen(string $period): JsonResponse
    {
        try {
            $this->service->reopenPeriod($period, (int) tenant('id'));
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => "Tax period {$period} reopened"]);
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
