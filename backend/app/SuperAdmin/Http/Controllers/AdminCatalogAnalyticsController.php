<?php

declare(strict_types=1);

namespace App\SuperAdmin\Http\Controllers;

use App\SuperAdmin\Application\CatalogAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class AdminCatalogAnalyticsController extends Controller
{
    public function __construct(private readonly CatalogAnalyticsService $service) {}

    /** GET /analytics/catalog/overview */
    public function overview(): JsonResponse
    {
        return response()->json(['data' => $this->service->overview()]);
    }

    /** GET /analytics/catalog/entitlement-heatmap */
    public function entitlementHeatmap(): JsonResponse
    {
        return response()->json(['data' => $this->service->entitlementHeatmap()]);
    }

    /** GET /analytics/catalog/addon-adoption */
    public function addonAdoption(): JsonResponse
    {
        return response()->json(['data' => $this->service->addonAdoption()]);
    }

    /** GET /analytics/catalog/revenue */
    public function revenueByItem(): JsonResponse
    {
        return response()->json(['data' => $this->service->revenueByItem()]);
    }

    /** GET /analytics/catalog/plan-distribution */
    public function planDistribution(): JsonResponse
    {
        return response()->json(['data' => $this->service->planDistribution()]);
    }

    /** GET /analytics/catalog/plan-changes */
    public function planChangeActivity(): JsonResponse
    {
        return response()->json(['data' => $this->service->planChangeActivity()]);
    }

    /** GET /analytics/catalog/pricing-rules */
    public function pricingRuleImpact(): JsonResponse
    {
        return response()->json(['data' => $this->service->pricingRuleImpact()]);
    }
}
