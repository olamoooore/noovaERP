<?php

namespace Modules\FinancialClose\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinancialClose\Services\FinancialCloseForecastService;

class ForecastController extends Controller
{
    public function __construct(
        private readonly FinancialCloseForecastService $forecastService,
    ) {}

    /** GET /cycles/{cycleId}/forecast */
    public function show(Request $request, string $cycleId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();

        $forecast = $this->forecastService->generateForecast($tenantId, $cycleId);

        return response()->json(['data' => $forecast]);
    }

    /** GET /forecast/{entityId}/executive?period_id= */
    public function executive(Request $request, string $entityId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $periodId = $request->query('period_id', '');

        $summary = $this->forecastService->getExecutiveSummary($tenantId, $entityId, $periodId);

        return response()->json(['data' => $summary]);
    }
}
