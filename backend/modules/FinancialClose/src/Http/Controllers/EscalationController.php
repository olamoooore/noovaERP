<?php

namespace Modules\FinancialClose\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinancialClose\Http\Requests\CreateEscalationRequest;
use Modules\FinancialClose\Http\Resources\EscalationResource;
use Modules\FinancialClose\Models\EscalationEvent;
use Modules\FinancialClose\Services\FinancialCloseEscalationService;

class EscalationController extends Controller
{
    public function __construct(
        private readonly FinancialCloseEscalationService $escalationService,
    ) {}

    /** GET /tasks/{taskId}/escalations */
    public function index(Request $request, string $taskId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();

        $escalations = EscalationEvent::where('tenant_id', $tenantId)
            ->where('task_id', $taskId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => EscalationResource::collection($escalations)]);
    }

    /** POST /tasks/{taskId}/escalations */
    public function store(CreateEscalationRequest $request, string $taskId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();

        $escalation = $this->escalationService->createEscalation(
            $tenantId,
            $taskId,
            $request->input('level', 'medium'),
            $request->input('reason'),
            $request->input('escalated_to'),
        );

        if (! $escalation) {
            return response()->json(['message' => 'Escalation could not be created.'], 422);
        }

        return response()->json(['data' => new EscalationResource($escalation)], 201);
    }

    /** POST /escalations/{escalationId}/resolve */
    public function resolve(Request $request, string $escalationId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId = $request->user()->id;

        $success = $this->escalationService->resolveEscalation($tenantId, $escalationId, $userId);

        if (! $success) {
            return response()->json(['message' => 'Escalation not found or already resolved.'], 422);
        }

        return response()->json(['message' => 'Escalation resolved.']);
    }

    /** POST /escalations/{escalationId}/return */
    public function return(Request $request, string $escalationId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId = $request->user()->id;
        $reason = $request->input('reason');

        $success = $this->escalationService->returnEscalation($tenantId, $escalationId, $userId, $reason);

        if (! $success) {
            return response()->json(['message' => 'Escalation not found or already resolved.'], 422);
        }

        return response()->json(['message' => 'Escalation returned to preparer.']);
    }

    /** POST /escalations/{escalationId}/approve */
    public function approve(Request $request, string $escalationId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId = $request->user()->id;
        $notes = $request->input('notes');

        $success = $this->escalationService->approveEscalation($tenantId, $escalationId, $userId, $notes);

        if (! $success) {
            return response()->json(['message' => 'Escalation not found or already resolved.'], 422);
        }

        return response()->json(['message' => 'Escalation approved.']);
    }
}
