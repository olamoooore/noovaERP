<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Features\AI\Services\AIApprovalControlService;
use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * End-to-end AI Approval API (ADC §7).
 *
 * Exposes the AIApprovalControlService over a public, standardized JSON API:
 *   POST   /api/v1/ai/approvals           -> request approval
 *   GET    /api/v1/ai/approvals            -> list pending (current tenant)
 *   GET    /api/v1/ai/approvals/{id}       -> status
 *   POST   /api/v1/ai/approvals/{id}/approve
 *   POST   /api/v1/ai/approvals/{id}/reject
 */
class AIApprovalController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AIApprovalControlService $approvals,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'max:120'],
            'module' => ['nullable', 'string', 'max:120'],
            'entity_type' => ['nullable', 'string', 'max:120'],
            'entity_id' => ['nullable', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $this->approvals->requestApproval(
            $validated['action'],
            $request->user(),
            [
                'module' => $validated['module'] ?? null,
                'entity_type' => $validated['entity_type'] ?? null,
                'entity_id' => $validated['entity_id'] ?? null,
            ],
            $validated['reason'] ?? null,
        );

        return $this->success($result, 'Approval request submitted.', 201);
    }

    public function index(): JsonResponse
    {
        return $this->success(
            $this->approvals->getPendingApprovals(tenant('id')),
            'Pending AI approvals.',
        );
    }

    public function show(int $id): JsonResponse
    {
        $status = $this->approvals->checkApprovalStatus($id);
        if (! $status['exists']) {
            return $this->notFound('AI approval request not found.');
        }

        return $this->success($status, 'AI approval status.');
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $this->approvals->approve($id, $request->user(), $validated['comment'] ?? null);

        return $result['success']
            ? $this->success($result, $result['message'])
            : $this->error($result['message'], 422);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $result = $this->approvals->reject($id, $request->user(), $validated['reason']);

        return $result['success']
            ? $this->success($result, $result['message'])
            : $this->error($result['message'], 422);
    }
}
