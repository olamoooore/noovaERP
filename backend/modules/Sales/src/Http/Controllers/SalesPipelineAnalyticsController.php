<?php

namespace Modules\Sales\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Sales\Services\SalesPipelineAnalyticsService;

/**
 * Issue #150: sales-pipeline analytics endpoint (pre-invoice pipeline, win
 * rate, forecast, salesperson performance, product mix).
 */
class SalesPipelineAnalyticsController extends Controller
{
    public function __construct(
        private readonly SalesPipelineAnalyticsService $analytics,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
        ]);

        $from = isset($validated['from_date'])
            ? Carbon::parse($validated['from_date'])
            : now()->startOfMonth();
        $to = isset($validated['to_date'])
            ? Carbon::parse($validated['to_date'])
            : now()->endOfMonth();

        return response()->json([
            'data' => $this->analytics->pipeline($from, $to),
        ]);
    }
}
