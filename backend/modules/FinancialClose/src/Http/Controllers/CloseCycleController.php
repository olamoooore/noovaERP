<?php

namespace Modules\FinancialClose\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinancialClose\Services\FinancialCloseCycleService;
use Modules\FinancialClose\Http\Resources\CloseCycleResource;

class CloseCycleController extends Controller
{
    public function __construct(
        private readonly FinancialCloseCycleService $cycleService,
    ) {}

    /** GET /cycles?entity_id= */
    public function index(Request $request): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $entityId = $request->query('entity_id', '');

        $cycles = $this->cycleService->listCycles($tenantId, $entityId, $request->only('status'));

        return response()->json(['data' => $cycles]);
    }

    /** POST /cycles */
    public function store(Request $request): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId   = $request->user()->id;

        $validated = $request->validate([
            'entity_id' => ['required', 'string'],
            'period_id' => ['required', 'string'],
        ]);

        $cycle = $this->cycleService->createCycle(
            $tenantId,
            $validated['entity_id'],
            $validated['period_id'],
            $userId,
        );

        if (!$cycle) {
            return response()->json(['message' => 'An active cycle already exists for this period.'], 409);
        }

        return response()->json(['data' => new CloseCycleResource($cycle)], 201);
    }

    /** POST /cycles/{cycleId}/open */
    public function open(Request $request, string $cycleId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId   = $request->user()->id;

        $success = $this->cycleService->openCycle($tenantId, $cycleId, $userId);

        if (!$success) {
            return response()->json(['message' => 'Cycle cannot be opened. It must be in draft status.'], 422);
        }

        return response()->json(['message' => 'Cycle opened.']);
    }

    /** POST /cycles/{cycleId}/close */
    public function close(Request $request, string $cycleId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId   = $request->user()->id;

        $success = $this->cycleService->closeCycle($tenantId, $cycleId, $userId);

        if (!$success) {
            return response()->json(['message' => 'Cycle is not ready to close.'], 422);
        }

        return response()->json(['message' => 'Cycle closed.']);
    }
}
