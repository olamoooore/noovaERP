<?php

namespace Modules\FinancialClose\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinancialClose\Services\FinancialCloseReadinessService;
use Modules\FinancialClose\Http\Resources\ReadinessResource;

class ReadinessController extends Controller
{
    public function __construct(
        private readonly FinancialCloseReadinessService $readinessService,
    ) {}

    /** GET /readiness/{entityId}/{periodId} */
    public function show(Request $request, string $entityId, string $periodId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();

        $summary = $this->readinessService->getReadinessSummary($tenantId, $entityId, $periodId);

        return response()->json(['data' => $summary]);
    }

    /** POST /readiness/{entityId}/{periodId}/refresh */
    public function refresh(Request $request, string $entityId, string $periodId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $cycleId  = $request->input('cycle_id');

        $snapshot = $this->readinessService->generateSnapshot($tenantId, $entityId, $periodId, $cycleId);

        return response()->json(['data' => new ReadinessResource($snapshot)], 201);
    }
}
