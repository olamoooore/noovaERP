<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FiscalPeriod;
use App\Services\PeriodCloseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PeriodCloseController extends Controller
{
    public function __construct(
        private PeriodCloseService $periodCloseService
    ) {}

    /**
     * Get period close summary.
     */
    public function summary(string $periodId): JsonResponse
    {
        $period = FiscalPeriod::findOrFail($periodId);
        $summary = $this->periodCloseService->getPeriodSummary($period);

        return $this->success($summary);
    }

    /**
     * Validate period before close.
     */
    public function validatePeriod(string $periodId): JsonResponse
    {
        $period = FiscalPeriod::findOrFail($periodId);
        $issues = $this->periodCloseService->validateBeforeClose($period);

        return $this->success([
            'can_close' => empty($issues),
            'issues' => $issues,
        ]);
    }

    /**
     * Close a fiscal period.
     */
    public function close(Request $request, string $periodId): JsonResponse
    {
        $period = FiscalPeriod::findOrFail($periodId);
        $result = $this->periodCloseService->closePeriod($period, (string) $request->user()->id);

        return $this->success($result, 'Period closed successfully.');
    }

    /**
     * Reopen a fiscal period.
     */
    public function reopen(Request $request, string $periodId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $period = FiscalPeriod::findOrFail($periodId);
        $result = $this->periodCloseService->reopenPeriod(
            $period,
            (string) $request->user()->id,
            $validated['reason']
        );

        return $this->success($result, 'Period reopened successfully.');
    }
}
