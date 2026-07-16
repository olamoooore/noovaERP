<?php

namespace Modules\FinancialClose\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinancialClose\Http\Requests\CreateFindingRequest;
use Modules\FinancialClose\Http\Requests\EditFindingRequest;
use Modules\FinancialClose\Http\Resources\FindingResource;
use Modules\FinancialClose\Models\AiFinding;
use Modules\FinancialClose\Services\FinancialCloseAiAnalysisService;
use Modules\FinancialClose\Services\FinancialCloseAiFindingService;

class FindingController extends Controller
{
    public function __construct(
        private readonly FinancialCloseAiFindingService $findingService,
        private readonly FinancialCloseAiAnalysisService $aiAnalysisService,
    ) {}

    /** GET /findings — all findings for this tenant */
    public function listAll(Request $request): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();

        $query = AiFinding::where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('risk_status')) {
            $query->where('risk_status', $request->input('risk_status'));
        }

        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->input('entity_id'));
        }

        return response()->json(['data' => FindingResource::collection($query->get())]);
    }

    /** GET /reviews/{reviewId}/findings */
    public function index(Request $request, string $reviewId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();

        $findings = AiFinding::where('tenant_id', $tenantId)
            ->where('review_id', $reviewId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => FindingResource::collection($findings)]);
    }

    /** POST /reviews/{reviewId}/findings */
    public function store(CreateFindingRequest $request, string $reviewId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();

        $finding = $this->findingService->createFinding(
            $tenantId,
            $reviewId,
            $request->validated(),
        );

        if (! $finding) {
            return response()->json(['message' => 'Finding could not be created.'], 422);
        }

        return response()->json(['data' => new FindingResource($finding)], 201);
    }

    /** POST /findings/{findingId}/accept */
    public function accept(Request $request, string $findingId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId = $request->user()->id;

        $result = $this->findingService->acceptFinding($tenantId, $findingId, $userId);

        if (! $result['success']) {
            return response()->json(['message' => $result['reason']], 422);
        }

        return response()->json(['message' => 'Finding accepted.']);
    }

    /** POST /findings/{findingId}/edit */
    public function edit(EditFindingRequest $request, string $findingId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId = $request->user()->id;

        $result = $this->findingService->editFinding(
            $tenantId,
            $findingId,
            $userId,
            $request->input('edited_output'),
        );

        if (! $result['success']) {
            return response()->json(['message' => $result['reason']], 422);
        }

        return response()->json(['message' => 'Finding edited.']);
    }

    /** POST /findings/{findingId}/dismiss */
    public function dismiss(Request $request, string $findingId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId = $request->user()->id;

        $result = $this->findingService->dismissFinding($tenantId, $findingId, $userId);

        if (! $result['success']) {
            return response()->json(['message' => $result['reason']], 422);
        }

        return response()->json(['message' => 'Finding dismissed.']);
    }

    /** POST /findings/{findingId}/escalate */
    public function escalate(Request $request, string $findingId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId = $request->user()->id;

        $result = $this->findingService->escalateFinding($tenantId, $findingId, $userId);

        if (! $result['success']) {
            return response()->json(['message' => $result['reason']], 422);
        }

        return response()->json(['message' => 'Finding escalated.']);
    }

    /** POST /findings/refresh — re-run AI analysis, clearing stale findings first */
    public function refreshFindings(Request $request): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $entityId = $request->input('entity_id', 'entity-001');
        $periodId = $request->input('period_id', date('Y-m'));

        $result = $this->aiAnalysisService->analyze($tenantId, $entityId, $periodId, true);

        return response()->json([
            'message' => 'Findings refreshed.',
            'findings_created' => $result['findings_created'] ?? 0,
            'accounts_analyzed' => $result['accounts_analyzed'] ?? 0,
        ]);
    }
}
