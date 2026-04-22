<?php

namespace Modules\FinancialClose\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinancialClose\Services\FinancialCloseAiFindingService;
use Modules\FinancialClose\Http\Requests\CreateFindingRequest;
use Modules\FinancialClose\Http\Requests\EditFindingRequest;
use Modules\FinancialClose\Http\Resources\FindingResource;
use Modules\FinancialClose\Models\AiFinding;

class FindingController extends Controller
{
    public function __construct(
        private readonly FinancialCloseAiFindingService $findingService,
    ) {}

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

        if (!$finding) {
            return response()->json(['message' => 'Finding could not be created.'], 422);
        }

        return response()->json(['data' => new FindingResource($finding)], 201);
    }

    /** POST /findings/{findingId}/accept */
    public function accept(Request $request, string $findingId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId   = $request->user()->id;

        $success = $this->findingService->acceptFinding($tenantId, $findingId, $userId);

        if (!$success) {
            return response()->json(['message' => 'Finding not found or already resolved.'], 422);
        }

        return response()->json(['message' => 'Finding accepted.']);
    }

    /** POST /findings/{findingId}/edit */
    public function edit(EditFindingRequest $request, string $findingId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId   = $request->user()->id;

        $success = $this->findingService->editFinding(
            $tenantId,
            $findingId,
            $userId,
            $request->input('edited_output'),
        );

        if (!$success) {
            return response()->json(['message' => 'Finding not found.'], 422);
        }

        return response()->json(['message' => 'Finding edited.']);
    }

    /** POST /findings/{findingId}/dismiss */
    public function dismiss(Request $request, string $findingId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId   = $request->user()->id;

        $success = $this->findingService->dismissFinding($tenantId, $findingId, $userId);

        if (!$success) {
            return response()->json(['message' => 'Finding not found or cannot be dismissed.'], 422);
        }

        return response()->json(['message' => 'Finding dismissed.']);
    }

    /** POST /findings/{findingId}/escalate */
    public function escalate(Request $request, string $findingId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId   = $request->user()->id;

        $success = $this->findingService->escalateFinding($tenantId, $findingId, $userId);

        if (!$success) {
            return response()->json(['message' => 'Finding not found or cannot be escalated.'], 422);
        }

        return response()->json(['message' => 'Finding escalated.']);
    }
}
