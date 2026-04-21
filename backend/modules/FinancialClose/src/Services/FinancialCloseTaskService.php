<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Models\CloseTask;
use Modules\FinancialClose\Models\AuditLog;

class FinancialCloseTaskService
{
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

        return true;
    }

    /**
     * Complete a task
     */
    public function completeTask(string $tenantId, string $taskId, string $userId): bool
    {
        $task = $this->getTask($tenantId, $taskId);

        if (!$task || $task->status !== 'submitted') {
            return false;
        }

        $task->update(['status' => 'completed']);

        $this->writeAuditLog($tenantId, $userId, 'task_completed', $taskId);

        return true;
    }

    /**
     * Block a task
     */
    public function blockTask(string $tenantId, string $taskId, string $userId): bool
    {
        $task = $this->getTask($tenantId, $taskId);

        if (!$task) {
            return false;
        }

        $task->update(['status' => 'blocked']);

        $this->writeAuditLog($tenantId, $userId, 'task_blocked', $taskId);

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
    protected function writeAuditLog(string $tenantId, string $actorId, string $action, string $entityId): void
    {
        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => 'close_task',
            'entity_id' => $entityId,
        ]);
    }
}