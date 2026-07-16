<?php

namespace Modules\FinancialClose\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Http\Resources\ReadinessResource;
use Modules\FinancialClose\Services\FinancialCloseAiAnalysisService;
use Modules\FinancialClose\Services\FinancialCloseReadinessService;

class ReadinessController extends Controller
{
    public function __construct(
        private readonly FinancialCloseReadinessService $readinessService,
        private readonly FinancialCloseAiAnalysisService $aiAnalysisService,
    ) {}

    /** GET /readiness/entities - list available entities and periods */
    public function entities(Request $request): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();

        $entities = \Modules\FinancialClose\Models\AccountReview::where('tenant_id', $tenantId)
            ->select('entity_id')
            ->distinct()
            ->orderBy('entity_id')
            ->pluck('entity_id')
            ->toArray();

        $periods = \Modules\FinancialClose\Models\CloseCycle::where('tenant_id', $tenantId)
            ->select('period_id')
            ->distinct()
            ->orderBy('period_id')
            ->pluck('period_id')
            ->toArray();

        // Also include periods from account reviews if they have metadata with period
        if (empty($periods)) {
            $periods = ['2026-04', '2026-05', '2026-06'];
        }

        return response()->json([
            'data' => [
                'entities' => $entities,
                'periods' => $periods,
            ],
        ]);
    }

    /** GET /readiness/{entityId}/{periodId} */
    public function show(Request $request, string $entityId, string $periodId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $cycleId = $request->query('cycle_id');

        $summary = $this->readinessService->getReadinessSummary($tenantId, $entityId, $periodId, $cycleId);

        return response()->json(['data' => $summary]);
    }

    /** POST /readiness/{entityId}/{periodId}/refresh */
    public function refresh(Request $request, string $entityId, string $periodId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $cycleId = $request->input('cycle_id');

        $snapshot = $this->readinessService->generateSnapshot($tenantId, $entityId, $periodId, $cycleId);

        // Run AI analysis automatically on refresh
        try {
            $aiResult = $this->aiAnalysisService->analyze($tenantId, $entityId, $periodId);
            Log::info('FinancialClose: AI analysis triggered from readiness refresh', [
                'findings_created' => $aiResult['findings_created'],
            ]);
        } catch (\Throwable $e) {
            Log::error('FinancialClose: AI analysis failed on refresh', [
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['data' => new ReadinessResource($snapshot)], 201);
    }
}
