<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Accounting\ApprovalInstance;
use App\Models\Accounting\ApprovalWorkflow;
use App\Services\MultiStepApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovalWorkflowController extends Controller
{
    public function __construct(
        private MultiStepApprovalService $approvalService
    ) {}

    /**
     * List available approval workflows.
     */
    public function index(): JsonResponse
    {
        $workflows = ApprovalWorkflow::where('tenant_id', tenant()->id)
            ->active()
            ->withCount('steps')
            ->orderBy('name')
            ->get();

        return $this->success($workflows);
    }

    /**
     * Get workflow details including steps.
     */
    public function show(string $id): JsonResponse
    {
        $workflow = ApprovalWorkflow::where('tenant_id', tenant()->id)
            ->with('steps')
            ->findOrFail($id);

        return $this->success($workflow);
    }

    /**
     * Initiate a multi-step approval workflow.
     */
    public function initiate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'workflow_code' => 'required|string|max:50',
            'entity_type' => 'required|string|max:100',
            'entity_id' => 'required|string|max:100',
            'amount' => 'required|numeric|min:0',
            'reason' => 'nullable|string|max:255',
        ]);

        $instance = $this->approvalService->initiate(
            $validated['workflow_code'],
            $validated['entity_type'],
            $validated['entity_id'],
            (float) $validated['amount'],
            (string) $request->user()->id,
            $validated['reason'] ?? null
        );

        return $this->success($instance, 'Approval workflow initiated.', 201);
    }

    /**
     * Approve a step.
     */
    public function approveStep(Request $request, string $instanceId): JsonResponse
    {
        $validated = $request->validate([
            'step_order' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:500',
        ]);

        $instance = $this->approvalService->approveStep(
            $instanceId,
            (int) $validated['step_order'],
            (string) $request->user()->id,
            $validated['notes'] ?? null
        );

        return $this->success($instance, 'Step approved.');
    }

    /**
     * Reject a step.
     */
    public function rejectStep(Request $request, string $instanceId): JsonResponse
    {
        $validated = $request->validate([
            'step_order' => 'required|integer|min:1',
            'reason' => 'required|string|max:500',
        ]);

        $instance = $this->approvalService->rejectStep(
            $instanceId,
            (int) $validated['step_order'],
            (string) $request->user()->id,
            $validated['reason']
        );

        return $this->success($instance, 'Step rejected.');
    }

    /**
     * Get pending approvals for the current user.
     */
    public function myPending(Request $request): JsonResponse
    {
        $approvals = $this->approvalService->getPendingForUser((string) $request->user()->id);

        return $this->success($approvals);
    }

    /**
     * Get active approval instances.
     */
    public function active(Request $request): JsonResponse
    {
        $entityType = $request->query('entity_type');
        $instances = $this->approvalService->getActiveInstances($entityType);

        return $this->success($instances);
    }
}
