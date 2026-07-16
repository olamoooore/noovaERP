<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Events\FinancialCloseTaskBlocked;
use Modules\FinancialClose\Events\FinancialCloseTaskCompleted;
use Modules\FinancialClose\Events\FinancialCloseTaskReturned;
use Modules\FinancialClose\Events\FinancialCloseTaskStarted;
use Modules\FinancialClose\Events\FinancialCloseTaskSubmitted;
use Modules\FinancialClose\Models\AuditLog;
use Modules\FinancialClose\Models\CloseTask;

class FinancialCloseTaskService
{
    protected FinancialCloseDependencyService $dependencyService;

    protected FinancialCloseReadinessService $readinessService;

    public function __construct(FinancialCloseDependencyService $dependencyService, FinancialCloseReadinessService $readinessService)
    {
        $this->dependencyService = $dependencyService;
        $this->readinessService = $readinessService;
    }

    /**
     * Get the dependency service instance.
     */
    public function getDependencyService(): FinancialCloseDependencyService
    {
        return $this->dependencyService;
    }

    /**
     * Dispatch a readiness refresh for the task's cycle.
     */
    private function refreshReadiness(string $tenantId, string $cycleId): void
    {
        try {
            $this->readinessService->dispatchRefreshForCycle($tenantId, $cycleId);
        } catch (\Throwable $e) {
            Log::warning('FinancialClose: failed to dispatch readiness refresh', [
                'tenant_id' => $tenantId,
                'cycle_id' => $cycleId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get all tasks for a cycle
     */
    public function listTasks(string $tenantId, string $cycleId, array $filters = []): array
    {
        $query = CloseTask::where('tenant_id', $tenantId)
            ->where('cycle_id', $cycleId);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['assigned_to'])) {
            $query->where('assigned_to', $filters['assigned_to']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        return $query->orderBy('created_at', 'desc')->get()->toArray();
    }

    /**
     * Get a single task
     */
    public function getTask(string $tenantId, string $taskId): ?CloseTask
    {
        return CloseTask::where('tenant_id', $tenantId)
            ->where('id', $taskId)
            ->first();
    }

    /**
     * Start a task
     */
    public function startTask(string $tenantId, string $taskId, string $userId): bool
    {
        $task = $this->getTask($tenantId, $taskId);

        if (! $task || ! in_array($task->status, ['assigned', 'created'])) {
            return false;
        }

        $task->update(['status' => 'in_progress']);
        $this->writeAuditLog($tenantId, $userId, 'task_started', $taskId);
        event(new FinancialCloseTaskStarted($tenantId, $taskId, $userId));
        $this->refreshReadiness($tenantId, $task->cycle_id);

        return true;
    }

    /**
     * Submit a task for review
     */
    public function submitTask(string $tenantId, string $taskId, string $userId): bool
    {
        $task = $this->getTask($tenantId, $taskId);

        if (! $task || $task->status !== 'in_progress') {
            return false;
        }

        $task->update(['status' => 'submitted']);
        $this->writeAuditLog($tenantId, $userId, 'task_submitted', $taskId);
        event(new FinancialCloseTaskSubmitted($tenantId, $taskId, $userId, $task->cycle_id));
        $this->refreshReadiness($tenantId, $task->cycle_id);

        return true;
    }

    /**
     * Return a task to the assignee.
     * Role check: only Manager, Admin, Owner can return submitted tasks.
     */
    public function returnTask(string $tenantId, string $taskId, string $userId, ?string $userRole = null): bool
    {
        $task = $this->getTask($tenantId, $taskId);

        if (! $task || $task->status !== 'submitted') {
            return false;
        }

        // Role-based access control: only reviewer roles can return tasks
        $reviewerRoles = ['owner', 'admin', 'manager'];
        if ($userRole && ! in_array(strtolower($userRole), $reviewerRoles)) {
            Log::warning('FinancialClose: task return denied — insufficient role', [
                'tenant_id' => $tenantId,
                'task_id' => $taskId,
                'user_id' => $userId,
                'user_role' => $userRole,
            ]);

            $this->writeAuditLog($tenantId, $userId, 'task_return_denied_role', $taskId, [
                'user_role' => $userRole,
                'required_roles' => $reviewerRoles,
            ]);

            return false;
        }

        $task->update(['status' => 'returned']);
        $this->writeAuditLog($tenantId, $userId, 'task_returned', $taskId);
        event(new FinancialCloseTaskReturned($tenantId, $taskId, $userId));
        $this->refreshReadiness($tenantId, $task->cycle_id);

        return true;
    }

    /**
     * Complete a task — dependency-aware.
     * Hard dependencies that are not yet completed WILL deny completion.
     * Checklist items and evidence must be resolved.
     * Role check: only Manager, Admin, Owner can complete (reviewer roles).
     * Separation-of-duties: completer must differ from the assigned preparer.
     */
    public function completeTask(string $tenantId, string $taskId, string $userId, ?string $userRole = null): array
    {
        $task = $this->getTask($tenantId, $taskId);

        if (! $task || $task->status !== 'submitted') {
            return ['success' => false, 'reason' => 'Task not found or not in submitted state'];
        }

        // Role-based access control: only reviewer roles can complete/approve tasks
        $reviewerRoles = ['owner', 'admin', 'manager'];
        if ($userRole && ! in_array(strtolower($userRole), $reviewerRoles)) {
            Log::warning('FinancialClose: task completion denied — insufficient role', [
                'tenant_id' => $tenantId,
                'task_id' => $taskId,
                'user_id' => $userId,
                'user_role' => $userRole,
            ]);

            $this->writeAuditLog($tenantId, $userId, 'task_completion_denied_role', $taskId, [
                'user_role' => $userRole,
                'required_roles' => $reviewerRoles,
            ]);

            return [
                'success' => false,
                'reason' => 'Only reviewers (Manager, Admin, Owner) can approve submitted tasks',
            ];
        }

        // Separation-of-duties: the completer must not be the same user who prepared the task
        if ($task->assigned_to && (string) $task->assigned_to === (string) $userId) {
            Log::warning('FinancialClose: task completion denied — separation of duties violated', [
                'tenant_id' => $tenantId,
                'task_id' => $taskId,
                'user_id' => $userId,
                'assigned_to' => $task->assigned_to,
            ]);

            $this->writeAuditLog($tenantId, $userId, 'task_completion_denied_sod', $taskId, [
                'assigned_to' => $task->assigned_to,
            ]);

            return [
                'success' => false,
                'reason' => 'You cannot approve a task you prepared. Another reviewer must approve it.',
            ];
        }

        if (! $this->dependencyService->canComplete($tenantId, $taskId)) {
            $blockers = $this->dependencyService->getUnresolvedHardDependencies($tenantId, $taskId);

            Log::warning('FinancialClose: task completion denied — unresolved hard dependencies', [
                'tenant_id' => $tenantId,
                'task_id' => $taskId,
                'blockers' => $blockers,
            ]);

            $this->writeAuditLog($tenantId, $userId, 'task_completion_denied_dependency', $taskId, [
                'blockers' => $blockers,
            ]);

            return [
                'success' => false,
                'reason' => 'Hard dependency unresolved',
                'blockers' => $blockers,
            ];
        }

        // Pre-flight checks: checklist + evidence
        $preFlightBlockers = $this->getPreFlightBlockers($tenantId, $task);
        if (! empty($preFlightBlockers)) {
            Log::warning('FinancialClose: task completion denied — pre-flight checks failed', [
                'tenant_id' => $tenantId,
                'task_id' => $taskId,
                'blockers' => $preFlightBlockers,
            ]);

            $this->writeAuditLog($tenantId, $userId, 'task_completion_denied_preflight', $taskId, [
                'blockers' => $preFlightBlockers,
            ]);

            return [
                'success' => false,
                'reason' => 'Pre-flight checks failed',
                'blockers' => $preFlightBlockers,
            ];
        }

        $task->update(['status' => 'completed', 'completed_at' => now()]);
        $this->writeAuditLog($tenantId, $userId, 'task_completed', $taskId);

        event(new FinancialCloseTaskCompleted($tenantId, $taskId, $userId, $task->cycle_id));

        // Unblock any downstream tasks that had this task as a hard dependency
        $this->dependencyService->unblockDownstreamTasks($tenantId, $taskId);

        return ['success' => true];
    }

    /**
     * Check pre-flight conditions that must be met before completing a task.
     * Public wrapper for external access.
     * Returns an array of blocker messages (empty = all clear).
     */
    public function getPreFlightBlockersForTask(string $tenantId, CloseTask $task): array
    {
        return $this->getPreFlightBlockers($tenantId, $task);
    }

    /**
     * Check pre-flight conditions that must be met before completing a task.
     * Returns an array of blocker messages (empty = all clear).
     */
    private function getPreFlightBlockers(string $tenantId, CloseTask $task): array
    {
        $blockers = [];
        $metadata = is_array($task->metadata) ? $task->metadata : [];

        // 1. Checklist: all items must be checked
        $checklistItems = $metadata['checklist_items'] ?? [];
        $checklistCompleted = $metadata['checklist_completed'] ?? [];
        if (count($checklistItems) > 0) {
            $missing = array_diff($checklistItems, $checklistCompleted);
            if (! empty($missing)) {
                $blockers[] = [
                    'type' => 'checklist',
                    'message' => count($missing).' checklist item(s) not yet completed',
                    'details' => array_values($missing),
                ];
            }
        }

        // 2. Evidence: at least one file must be uploaded (if the task has document links)
        $evidenceCount = \Modules\FinancialClose\Models\TaskEvidenceLink::where('tenant_id', $tenantId)
            ->where('task_id', $task->id)
            ->count();
        $documentLinks = $metadata['document_links'] ?? [];
        if (! empty($documentLinks) && $evidenceCount === 0) {
            $blockers[] = [
                'type' => 'evidence',
                'message' => 'At least one evidence file must be uploaded for linked source documents',
            ];
        }

        return $blockers;
    }

    /**
     * Block a task with a mandatory reason
     */
    public function blockTask(string $tenantId, string $taskId, string $userId, string $reason = ''): bool
    {
        $task = $this->getTask($tenantId, $taskId);

        if (! $task) {
            return false;
        }

        $task->update(['status' => 'blocked']);
        $this->writeAuditLog($tenantId, $userId, 'task_blocked', $taskId, ['reason' => $reason]);
        event(new FinancialCloseTaskBlocked($tenantId, $taskId, $userId, $reason));
        $this->refreshReadiness($tenantId, $task->cycle_id);

        return true;
    }

    /**
     * Cancel a task
     */
    public function cancelTask(string $tenantId, string $taskId, string $userId): bool
    {
        $task = $this->getTask($tenantId, $taskId);

        if (! $task || $task->status === 'completed') {
            return false;
        }

        $task->update(['status' => 'cancelled']);

        $this->writeAuditLog($tenantId, $userId, 'task_cancelled', $taskId);
        $this->refreshReadiness($tenantId, $task->cycle_id);

        return true;
    }

    /**
     * Get my tasks queue for a user
     */
    public function getMyTasks(string $tenantId, string $userId): array
    {
        return CloseTask::where('tenant_id', $tenantId)
            ->where('assigned_to', $userId)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->orderBy('due_date', 'asc')
            ->get()
            ->toArray();
    }

    /**
     * Get review queue -- submitted tasks awaiting review
     */
    public function getReviewQueue(string $tenantId): array
    {
        return CloseTask::where('tenant_id', $tenantId)
            ->where('status', 'submitted')
            ->orderBy('due_date', 'asc')
            ->get()
            ->toArray();
    }

    /**
     * Write audit log entry
     */
    protected function writeAuditLog(string $tenantId, string $actorId, string $action, string $entityId, array $context = []): void
    {
        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => 'close_task',
            'entity_id' => $entityId,
            'after' => $context ?: null,
        ]);
    }
}
