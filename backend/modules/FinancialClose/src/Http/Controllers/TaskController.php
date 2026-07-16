<?php

namespace Modules\FinancialClose\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\FinancialClose\Http\Requests\BlockTaskRequest;
use Modules\FinancialClose\Http\Requests\LinkDocumentRequest;
use Modules\FinancialClose\Http\Resources\TaskResource;
use Modules\FinancialClose\Models\CloseCycle;
use Modules\FinancialClose\Models\CloseTask;
use Modules\FinancialClose\Models\RestrictedDocumentAccessLog;
use Modules\FinancialClose\Models\SupportingEvidence;
use Modules\FinancialClose\Models\TaskEvidenceLink;
use Modules\FinancialClose\Services\FileAccessBrokerService;
use Modules\FinancialClose\Services\FinancialCloseAssignmentService;
use Modules\FinancialClose\Services\FinancialCloseTaskService;

class TaskController extends Controller
{
    public function __construct(
        private readonly FinancialCloseTaskService $taskService,
        private readonly FinancialCloseAssignmentService $assignmentService,
        private readonly FileAccessBrokerService $fileAccessBroker,
    ) {}

    /** GET /users — list users available for task assignment */
    public function users(): JsonResponse
    {
        $users = \App\Models\User::select('id', 'name', 'email')->get();

        return response()->json(['data' => $users]);
    }

    /** GET /cycles/{cycleId}/tasks */
    public function index(Request $request, string $cycleId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();

        $cycleExists = CloseCycle::where('tenant_id', $tenantId)
            ->where('id', $cycleId)
            ->exists();

        if (! $cycleExists) {
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
        $userId = (string) $request->user()->id;
        $status = $request->query('status');

        $query = CloseTask::where('tenant_id', $tenantId)
            ->where('assigned_to', $userId);

        if ($status === 'completed') {
            $query->where('status', 'completed');
        } elseif ($status === 'cancelled') {
            $query->where('status', 'cancelled');
        } elseif ($status === 'all') {
            // No status filter — return everything
        } elseif ($status) {
            $query->where('status', $status);
        } else {
            // Default: active tasks only (exclude completed/cancelled)
            $query->whereNotIn('status', ['completed', 'cancelled']);
        }

        $tasks = $query->orderBy('due_date')
            ->get();

        return response()->json(['data' => TaskResource::collection($tasks)]);
    }

    /** GET /tasks/review-queue */
    public function reviewQueue(Request $request): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId = $request->user()->id;

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
        if (! empty($escalatedTaskIds)) {
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

        if (! $task) {
            return response()->json(['message' => 'Task not found.'], 404);
        }

        return response()->json(['data' => new TaskResource($task)]);
    }

    /** POST /tasks/{taskId}/start */
    public function start(Request $request, string $taskId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId = $request->user()->id;

        $success = $this->taskService->startTask($tenantId, $taskId, $userId);

        if (! $success) {
            return response()->json(['message' => 'Task cannot be started.'], 422);
        }

        return response()->json(['message' => 'Task started.']);
    }

    /** POST /tasks/{taskId}/submit */
    public function submit(Request $request, string $taskId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId = $request->user()->id;

        $success = $this->taskService->submitTask($tenantId, $taskId, $userId);

        if (! $success) {
            return response()->json(['message' => 'Task cannot be submitted.'], 422);
        }

        return response()->json(['message' => 'Task submitted for review.']);
    }

    /** GET /tasks/{taskId}/blockers */
    public function checkBlockers(Request $request, string $taskId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();

        $task = CloseTask::where('tenant_id', $tenantId)->where('id', $taskId)->first();
        if (! $task) {
            return response()->json(['message' => 'Task not found.'], 404);
        }

        $blockers = [];

        // Check dependency blockers
        if (! $this->taskService->getDependencyService()->canComplete($tenantId, $taskId)) {
            $depBlockers = $this->taskService->getDependencyService()->getUnresolvedHardDependencies($tenantId, $taskId);
            foreach ($depBlockers as $dep) {
                $blockers[] = [
                    'type' => 'dependency',
                    'message' => $dep['depends_on_task_title'] ?? 'Upstream task',
                    'details' => $dep['depends_on_status'] ?? 'unknown',
                ];
            }
        }

        // Check pre-flight blockers (checklist, evidence)
        $preFlightBlockers = $this->taskService->getPreFlightBlockersForTask($tenantId, $task);
        $blockers = array_merge($blockers, $preFlightBlockers);

        return response()->json([
            'can_complete' => empty($blockers),
            'blockers' => $blockers,
        ]);
    }

    /** POST /tasks/{taskId}/complete */
    public function complete(Request $request, string $taskId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId = $request->user()->id;
        $userRole = $this->getUserRole($request);

        $result = $this->taskService->completeTask($tenantId, $taskId, $userId, $userRole);

        if (! $result['success']) {
            return response()->json([
                'message' => $result['reason'],
                'blockers' => $result['blockers'] ?? [],
            ], 422);
        }

        return response()->json(['message' => 'Task completed.']);
    }

    /** POST /tasks/{taskId}/return */
    public function returnTask(Request $request, string $taskId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId = $request->user()->id;
        $userRole = $this->getUserRole($request);

        $success = $this->taskService->returnTask($tenantId, $taskId, $userId, $userRole);

        if (! $success) {
            return response()->json(['message' => 'Task cannot be returned. Only reviewers (Manager, Admin, Owner) can return tasks.'], 422);
        }

        return response()->json(['message' => 'Task returned.']);
    }

    /**
     * Resolve the user's tenant-scoped role from the user_company_access table.
     */
    private function getUserRole(Request $request): ?string
    {
        $tenantId = tenant()->getTenantKey();
        $userId = $request->user()->id;

        return \DB::table('public.user_company_access')
            ->where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->value('role');
    }

    /** POST /tasks/{taskId}/block */
    public function block(BlockTaskRequest $request, string $taskId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId = $request->user()->id;

        $success = $this->taskService->blockTask($tenantId, $taskId, $userId, $request->input('reason'));

        if (! $success) {
            return response()->json(['message' => 'Task cannot be blocked.'], 422);
        }

        return response()->json(['message' => 'Task blocked.']);
    }

    /** POST /tasks/{taskId}/assign */
    public function assign(Request $request, string $taskId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId = (string) $request->user()->id;

        $request->validate(['assigned_to' => ['required', 'string']]);

        $success = $this->assignmentService->assignTask($tenantId, $taskId, $request->input('assigned_to'), $userId);

        if (! $success) {
            return response()->json(['message' => 'Task cannot be assigned.'], 422);
        }

        return response()->json(['message' => 'Task assigned.']);
    }

    /** POST /tasks/{taskId}/reassign */
    public function reassign(Request $request, string $taskId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId = (string) $request->user()->id;

        $request->validate(['assigned_to' => ['required', 'string']]);

        $success = $this->assignmentService->reassignTask($tenantId, $taskId, $request->input('assigned_to'), $userId);

        if (! $success) {
            return response()->json(['message' => 'Task cannot be reassigned.'], 422);
        }

        return response()->json(['message' => 'Task reassigned.']);
    }

    /** GET /tasks/{taskId}/assignments */
    public function assignmentHistory(string $taskId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $history = $this->assignmentService->getAssignmentHistory($tenantId, $taskId);

        return response()->json(['data' => $history]);
    }

    /** POST /tasks/{taskId}/checklist */
    public function saveChecklist(Request $request, string $taskId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();

        $task = CloseTask::where('tenant_id', $tenantId)->where('id', $taskId)->first();
        if (! $task) {
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
        $userId = $request->user()->id;

        $task = CloseTask::where('tenant_id', $tenantId)->where('id', $taskId)->first();
        if (! $task) {
            return response()->json(['message' => 'Task not found.'], 404);
        }

        // Prevent evidence upload if task is completed or cancelled
        if (in_array($task->status, ['completed', 'cancelled'])) {
            return response()->json(['message' => 'Cannot upload evidence to a completed or cancelled task.'], 422);
        }

        $request->validate(['file' => ['required', 'file', 'max:10240']]);

        $file = $request->file('file');
        $path = $file->store('close-evidence/'.$taskId, 'public');

        $evidence = SupportingEvidence::create([
            'tenant_id' => $tenantId,
            'review_id' => null,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'status' => 'attached',
            'uploaded_by' => $userId,
        ]);

        TaskEvidenceLink::create([
            'tenant_id' => $tenantId,
            'task_id' => $taskId,
            'evidence_id' => $evidence->id,
        ]);

        return response()->json(['message' => 'Evidence uploaded.', 'data' => [
            'id' => $evidence->id,
            'file_name' => $evidence->file_name,
            'file_path' => $evidence->file_path,
            'status' => $evidence->status,
            'created_at' => $evidence->created_at,
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
                if (! $e) {
                    return null;
                }

                return [
                    'id' => $e->id,
                    'file_name' => $e->file_name,
                    'file_path' => $e->file_path,
                    'status' => $e->status,
                    'uploaded_by' => $e->uploaded_by,
                    'created_at' => $e->created_at,
                    'url' => $e->file_path ? Storage::disk('public')->url($e->file_path) : null,
                ];
            })
            ->filter()
            ->values();

        return response()->json(['data' => $evidence]);
    }

    /** GET /tasks/{taskId}/evidence/{evidenceId}/download */
    public function downloadEvidence(Request $request, string $taskId, string $evidenceId): Response|JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId = $request->user()->id;

        // Check if user can download evidence
        if (! $this->fileAccessBroker->authorizeAccess($tenantId, $userId, $evidenceId)) {
            return response()->json(['message' => 'Unauthorized to download this evidence.'], 403);
        }

        $evidence = SupportingEvidence::where('tenant_id', $tenantId)
            ->where('id', $evidenceId)
            ->first();

        if (! $evidence || ! $evidence->file_path) {
            return response()->json(['message' => 'Evidence not found or file missing.'], 404);
        }

        // Log the view access
        RestrictedDocumentAccessLog::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'document_type' => 'evidence',
            'document_id' => $evidenceId,
            'action' => 'view',
            'was_authorized' => true,
        ]);

        $path = $evidence->file_path;
        if (! Storage::disk('public')->exists($path)) {
            return response()->json(['message' => 'File not found on disk.'], 404);
        }

        $fileContents = Storage::disk('public')->get($path);
        $mimeType = Storage::disk('public')->mimeType($path);
        $fileName = $evidence->file_name;

        return response($fileContents, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="'.$fileName.'"',
        ]);
    }

    /** GET /tasks/{taskId}/evidence/audit-trail */
    public function evidenceAuditTrail(Request $request, string $taskId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();

        // Get all evidence IDs for this task
        $evidenceIds = TaskEvidenceLink::where('tenant_id', $tenantId)
            ->where('task_id', $taskId)
            ->pluck('evidence_id')
            ->toArray();

        if (empty($evidenceIds)) {
            return response()->json(['data' => []]);
        }

        // Get access logs for these evidence items
        $logs = RestrictedDocumentAccessLog::where('tenant_id', $tenantId)
            ->where('document_type', 'evidence')
            ->whereIn('document_id', $evidenceIds)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'user_id' => $log->user_id,
                    'action' => $log->action,
                    'was_authorized' => $log->was_authorized,
                    'created_at' => $log->created_at,
                ];
            });

        return response()->json(['data' => $logs]);
    }

    /** POST /tasks/{taskId}/link-document */
    public function linkDocument(LinkDocumentRequest $request, string $taskId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();

        $task = CloseTask::where('tenant_id', $tenantId)->where('id', $taskId)->first();
        if (! $task) {
            return response()->json(['message' => 'Task not found.'], 404);
        }

        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $links = $metadata['document_links'] ?? [];

        $links[] = [
            'type' => $request->input('document_type'),
            'id' => (int) $request->input('document_id'),
            'title' => $request->input('title') ?? ucfirst($request->input('document_type')).' #'.$request->input('document_id'),
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
        if (! $task) {
            return response()->json(['message' => 'Task not found.'], 404);
        }

        $request->validate([
            'document_type' => ['required', 'string'],
            'document_id' => ['required', 'integer'],
        ]);

        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $links = $metadata['document_links'] ?? [];

        $metadata['document_links'] = array_values(array_filter($links, function ($link) use ($request) {
            return ! ($link['type'] === $request->input('document_type') && (int) $link['id'] === (int) $request->input('document_id'));
        }));

        $task->update(['metadata' => $metadata]);

        return response()->json(['message' => 'Document unlinked.', 'data' => $metadata['document_links']]);
    }
}
