<?php

namespace Modules\FinancialClose\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinancialClose\Services\FinancialCloseTaskService;
use Modules\FinancialClose\Http\Requests\BlockTaskRequest;
use Modules\FinancialClose\Http\Resources\TaskResource;
use Modules\FinancialClose\Models\CloseTask;

class TaskController extends Controller
{
    public function __construct(
        private readonly FinancialCloseTaskService $taskService,
    ) {}

    /** GET /cycles/{cycleId}/tasks */
    public function index(Request $request, string $cycleId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();

        $tasks = CloseTask::where('tenant_id', $tenantId)
            ->where('cycle_id', $cycleId)
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => TaskResource::collection($tasks)]);
    }

    /** GET /tasks/my */
    public function myTasks(Request $request): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();

        $tasks = CloseTask::where('tenant_id', $tenantId)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->orderBy('due_date')
            ->get();

        return response()->json(['data' => TaskResource::collection($tasks)]);
    }

    /** GET /tasks/review-queue */
    public function reviewQueue(Request $request): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();

        $tasks = CloseTask::where('tenant_id', $tenantId)
            ->where('status', 'submitted')
            ->orderBy('due_date')
            ->get();

        return response()->json(['data' => TaskResource::collection($tasks)]);
    }

    /** GET /tasks/{taskId} */
    public function show(Request $request, string $taskId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();

        $task = CloseTask::with([
                'dependencies.dependsOnTask',
                'escalations',
            ])
            ->where('tenant_id', $tenantId)
            ->where('id', $taskId)
            ->first();

        if (!$task) {
            return response()->json(['message' => 'Task not found.'], 404);
        }

        return response()->json(['data' => new TaskResource($task)]);
    }

    /** POST /tasks/{taskId}/start */
    public function start(Request $request, string $taskId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId   = $request->user()->id;

        $success = $this->taskService->startTask($tenantId, $taskId, $userId);

        if (!$success) {
            return response()->json(['message' => 'Task cannot be started.'], 422);
        }

        return response()->json(['message' => 'Task started.']);
    }

    /** POST /tasks/{taskId}/submit */
    public function submit(Request $request, string $taskId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId   = $request->user()->id;

        $success = $this->taskService->submitTask($tenantId, $taskId, $userId);

        if (!$success) {
            return response()->json(['message' => 'Task cannot be submitted.'], 422);
        }

        return response()->json(['message' => 'Task submitted for review.']);
    }

    /** POST /tasks/{taskId}/complete */
    public function complete(Request $request, string $taskId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId   = $request->user()->id;

        $result = $this->taskService->completeTask($tenantId, $taskId, $userId);

        if (!$result['success']) {
            return response()->json([
                'message'  => $result['reason'],
                'blockers' => $result['blockers'] ?? [],
            ], 422);
        }

        return response()->json(['message' => 'Task completed.']);
    }

    /** POST /tasks/{taskId}/return */
    public function returnTask(Request $request, string $taskId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId   = $request->user()->id;

        $success = $this->taskService->returnTask($tenantId, $taskId, $userId);

        if (!$success) {
            return response()->json(['message' => 'Task cannot be returned.'], 422);
        }

        return response()->json(['message' => 'Task returned.']);
    }

    /** POST /tasks/{taskId}/block */
    public function block(BlockTaskRequest $request, string $taskId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId   = $request->user()->id;

        $success = $this->taskService->blockTask($tenantId, $taskId, $userId, $request->input('reason'));

        if (!$success) {
            return response()->json(['message' => 'Task cannot be blocked.'], 422);
        }

        return response()->json(['message' => 'Task blocked.']);
    }
}
