<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Models\CloseTask;
use Modules\FinancialClose\Models\AuditLog;
use Modules\FinancialClose\Events\FinancialCloseTaskStarted;
use Modules\FinancialClose\Events\FinancialCloseTaskSubmitted;
use Modules\FinancialClose\Events\FinancialCloseTaskCompleted;
use Modules\FinancialClose\Events\FinancialCloseTaskReturned;
use Modules\FinancialClose\Events\FinancialCloseTaskBlocked;

class FinancialCloseTaskService
{
    protected FinancialCloseDependencyService $dependencyService;

    public function __construct(FinancialCloseDependencyService $dependencyService)
    {
        $this->dependencyService = $dependencyService;
    }

    /**
     * Get all tasks for a cycle
     */
    public function listTasks(string $tenantId, string $cycleId, array $filters = []): array
    {
        $query = CloseTask::where('tenant_id', $tenantId)
            ->where('cycle_id', $cycleId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['assigned_to'])) {
            $query->where('assigned_to', $filters['assigned_to']);
        }

        if (!empty($filters['priority'])) {
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

        if (!$task || !in_array($task->status, ['assigned', 'created'])) {
            return false;
        }

        $task->update(['status' => 'in_progress']);
        $this->writeAuditLog($tenantId, $userId, 'task_started', $taskId);
        event(new FinancialCloseTaskStarted($tenantId, $taskId, $userId));

        return true;
    }

    /**
     * Submit a task for review
     */
    public function submitTask(string $tenantId, string $taskId, string $userId): bool
    {
        $task = $this->getTask($tenantId, $taskId);

        if (!$task || $task->status !== 'in_progress') {
            return false;
        }

        $task->update(['status' => 'submitted']);
        $this->writeAuditLog($tenantId, $userId, 'task_submitted', $taskId);
        event(new FinancialCloseTaskSubmitted($tenantId, $taskId, $userId, $task->cycle_id));

        return true;
    }

    /**
     * Return a task to the assignee
     */
    public function returnTask(string $tenantId, string $taskId, string $userId): bool
    {
        $task = $this->getTask($tenantId, $taskId);

        if (!$task || $task->status !== 'submitted') {
            return false;
        }

        $task->update(['status' => 'returned']);
        $this->writeAuditLog($tenantId, $userId, 'task_returned', $taskId);
        event(new FinancialCloseTaskReturned($tenantId, $taskId, $userId));

        return true;
    }

    /**
     * Complete a task — dependency-aware.
     * Hard dependencies that are not yet completed WILL deny completion.
     * This is a non-negotiable orchestration rule.
     */
    public function completeTask(string $tenantId, string $taskId, string $userId): array
    {
        $task = $this->getTask($tenantId, $taskId);

        if (!$task || $task->status !== 'submitted') {
            return ['success' => false, 'reason' => 'Task not found or not in submitted state'];
        }

        if (!$this->dependencyService->canComplete($tenantId, $taskId)) {
            $blockers = $this->dependencyService->getUnresolvedHardDependencies($tenantId, $taskId);

            Log::warning('FinancialClose: task completion denied — unresolved hard dependencies', [
                'tenant_id' => $tenantId,
                'task_id'   => $taskId,
                'blockers'  => $blockers,
            ]);

            $this->writeAuditLog($tenantId, $userId, 'task_completion_denied_dependency', $taskId, [
                'blockers' => $blockers,
            ]);

            return [
                'success' => false,
                'reason'  => 'Hard dependency unresolved',
                'blockers' => $blockers,
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
     * Block a task with a mandatory reason
     */
    public function blockTask(string $tenantId, string $taskId, string $userId, string $reason = ''): bool
    {
        $task = $this->getTask($tenantId, $taskId);

        if (!$task) {
            return false;
        }

        $task->update(['status' => 'blocked']);
        $this->writeAuditLog($tenantId, $userId, 'task_blocked', $taskId, ['reason' => $reason]);
        event(new FinancialCloseTaskBlocked($tenantId, $taskId, $userId, $reason));

        return true;
    }

    /**
     * Cancel a task
     */
    public function cancelTask(string $tenantId, string $taskId, string $userId): bool
    {
        $task = $this->getTask($tenantId, $taskId);

        if (!$task || $task->status === 'completed') {
            return false;
        }

        $task->update(['status' => 'cancelled']);

        $this->writeAuditLog($tenantId, $userId, 'task_cancelled', $taskId);

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
            'tenant_id'   => $tenantId,
            'actor_id'    => $actorId,
            'action'      => $action,
            'entity_type' => 'close_task',
            'entity_id'   => $entityId,
            'after'       => $context ?: null,
        ]);
    }
}