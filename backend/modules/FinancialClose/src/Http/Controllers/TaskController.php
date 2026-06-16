<?php

namespace Modules\FinancialClose\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinancialClose\Services\FinancialCloseTaskService;
use Modules\FinancialClose\Http\Requests\BlockTaskRequest;
use Modules\FinancialClose\Http\Requests\LinkDocumentRequest;
use Modules\FinancialClose\Http\Resources\TaskResource;
use Modules\FinancialClose\Models\CloseTask;
use Modules\FinancialClose\Models\CloseCycle;
use Modules\FinancialClose\Models\SupportingEvidence;
use Modules\FinancialClose\Models\TaskEvidenceLink;
use Illuminate\Support\Facades\Storage;

class TaskController extends Controller
{
    public function __construct(
        private readonly FinancialCloseTaskService $taskService,
    ) {}

    /** GET /cycles/{cycleId}/tasks */
    public function index(Request $request, string $cycleId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();

        $cycleExists = CloseCycle::where('tenant_id', $tenantId)
            ->where('id', $cycleId)
            ->exists();

        if (!$cycleExists) {
            return response()->json(['message' => 'Cycle not found.'], 404);
        }

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
        $userId   = $request->user()->id;

        $tasks = CloseTask::where('tenant_id', $tenantId)
            ->where('assigned_to', $userId)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->orderBy('due_date')
            ->get();

        return response()->json(['data' => TaskResource::collection($tasks)]);
    }

    /** GET /tasks/review-queue */
    public function reviewQueue(Request $request): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId   = $request->user()->id;

        // Submitted tasks awaiting review
        $submittedTasks = CloseTask::where('tenant_id', $tenantId)
            ->where('status', 'submitted')
            ->orderBy('due_date')
            ->get();

        // Escalated tasks routed to this user (via open escalation events)
        $escalatedTaskIds = \Modules\FinancialClose\Models\EscalationEvent::where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->where('escalated_to', (string) $userId)
            ->pluck('task_id')
            ->unique()
            ->all();

        $escalatedTasks = [];
        if (!empty($escalatedTaskIds)) {
            $escalatedTasks = CloseTask::where('tenant_id', $tenantId)
                ->whereIn('id', $escalatedTaskIds)
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->orderBy('due_date')
                ->get()
                ->all();
        }

        // Merge and de-duplicate (a task could be both submitted and escalated)
        $merged = $submittedTasks
            ->merge(collect($escalatedTasks))
            ->unique('id')
            ->values();

        return response()->json(['data' => TaskResource::collection($merged)]);
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

    /** POST /tasks/{taskId}/checklist */
    public function saveChecklist(Request $request, string $taskId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();

        $task = CloseTask::where('tenant_id', $tenantId)->where('id', $taskId)->first();
        if (!$task) {
            return response()->json(['message' => 'Task not found.'], 404);
        }

        $request->validate(['completed_items' => ['required', 'array']]);
        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $metadata['checklist_completed'] = $request->input('completed_items');
        $task->update(['metadata' => $metadata]);

        return response()->json(['message' => 'Checklist progress saved.', 'data' => $metadata['checklist_completed']]);
    }

    /** POST /tasks/{taskId}/evidence */
    public function uploadEvidence(Request $request, string $taskId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId   = $request->user()->id;

        $task = CloseTask::where('tenant_id', $tenantId)->where('id', $taskId)->first();
        if (!$task) {
            return response()->json(['message' => 'Task not found.'], 404);
        }

        $request->validate(['file' => ['required', 'file', 'max:10240']]);

        $file = $request->file('file');
        $path = $file->store('close-evidence/' . $taskId, 'public');

        $evidence = SupportingEvidence::create([
            'tenant_id'  => $tenantId,
            'review_id'  => null,
            'file_name'  => $file->getClientOriginalName(),
            'file_path'  => $path,
            'status'     => 'attached',
            'uploaded_by'=> $userId,
        ]);

        TaskEvidenceLink::create([
            'tenant_id'   => $tenantId,
            'task_id'     => $taskId,
            'evidence_id' => $evidence->id,
        ]);

        return response()->json(['message' => 'Evidence uploaded.', 'data' => [
            'id'        => $evidence->id,
            'file_name' => $evidence->file_name,
            'file_path' => $evidence->file_path,
            'status'    => $evidence->status,
            'created_at'=> $evidence->created_at,
        ]], 201);
    }

    /** GET /tasks/{taskId}/evidence */
    public function listEvidence(Request $request, string $taskId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();

        $evidence = TaskEvidenceLink::where('tenant_id', $tenantId)
            ->where('task_id', $taskId)
            ->with('evidence')
            ->get()
            ->map(function ($link) {
                $e = $link->evidence;
                if (!$e) return null;
                return [
                    'id'         => $e->id,
                    'file_name'  => $e->file_name,
                    'file_path'  => $e->file_path,
                    'status'     => $e->status,
                    'uploaded_by'=> $e->uploaded_by,
                    'created_at' => $e->created_at,
                    'url'        => $e->file_path ? Storage::disk('public')->url($e->file_path) : null,
                ];
            })
            ->filter()
            ->values();

        return response()->json(['data' => $evidence]);
    }

    /** POST /tasks/{taskId}/link-document */
    public function linkDocument(LinkDocumentRequest $request, string $taskId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();

        $task = CloseTask::where('tenant_id', $tenantId)->where('id', $taskId)->first();
        if (!$task) {
            return response()->json(['message' => 'Task not found.'], 404);
        }

        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $links = $metadata['document_links'] ?? [];

        $links[] = [
            'type'  => $request->input('document_type'),
            'id'    => (int) $request->input('document_id'),
            'title' => $request->input('title') ?? ucfirst($request->input('document_type')) . ' #' . $request->input('document_id'),
        ];

        $metadata['document_links'] = $links;
        $task->update(['metadata' => $metadata]);

        return response()->json(['message' => 'Document linked.', 'data' => $links], 201);
    }

    /** DELETE /tasks/{taskId}/link-document */
    public function unlinkDocument(Request $request, string $taskId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();

        $task = CloseTask::where('tenant_id', $tenantId)->where('id', $taskId)->first();
        if (!$task) {
            return response()->json(['message' => 'Task not found.'], 404);
        }

        $request->validate([
            'document_type' => ['required', 'string'],
            'document_id'   => ['required', 'integer'],
        ]);

        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $links = $metadata['document_links'] ?? [];

        $metadata['document_links'] = array_values(array_filter($links, function ($link) use ($request) {
            return !($link['type'] === $request->input('document_type') && (int) $link['id'] === (int) $request->input('document_id'));
        }));

        $task->update(['metadata' => $metadata]);

        return response()->json(['message' => 'Document unlinked.', 'data' => $metadata['document_links']]);
    }
}
