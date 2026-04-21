<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Models\CloseTask;
use Modules\FinancialClose\Models\TaskAssignment;
use Modules\FinancialClose\Models\AuditLog;

class FinancialCloseAssignmentService
{
    /**
     * Assign a task to a user
     */
    public function assignTask(string $tenantId, string $taskId, string $assignedTo, string $assignedBy): bool
    {
        $task = CloseTask::where('tenant_id', $tenantId)
            ->where('id', $taskId)
            ->first();

        if (!$task) {
            return false;
        }

        if (in_array($task->status, ['completed', 'cancelled'])) {
            Log::warning('FinancialClose: cannot assign completed or cancelled task', [
                'task_id' => $taskId,
                'status' => $task->status,
            ]);
            return false;
        }

        $task->update([
            'assigned_to' => $assignedTo,
            'status' => 'assigned',
        ]);

        TaskAssignment::create([
            'tenant_id' => $tenantId,
            'task_id' => $taskId,
            'assigned_to' => $assignedTo,
            'assigned_by' => $assignedBy,
            'assigned_at' => now(),
        ]);

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $assignedBy,
            'action' => 'task_assigned',
            'entity_type' => 'close_task',
            'entity_id' => $taskId,
            'after' => ['assigned_to' => $assignedTo],
        ]);

        return true;
    }

    /**
     * Reassign a task to a different user
     */
    public function reassignTask(string $tenantId, string $taskId, string $assignedTo, string $assignedBy): bool
    {
        $task = CloseTask::where('tenant_id', $tenantId)
            ->where('id', $taskId)
            ->first();

        if (!$task) {
            return false;
        }

        if (in_array($task->status, ['completed', 'cancelled'])) {
            Log::warning('FinancialClose: cannot reassign completed or cancelled task', [
                'task_id' => $taskId,
                'status' => $task->status,
            ]);
            return false;
        }

        $previousAssignee = $task->assigned_to;

        $task->update(['assigned_to' => $assignedTo]);

        TaskAssignment::create([
            'tenant_id' => $tenantId,
            'task_id' => $taskId,
            'assigned_to' => $assignedTo,
            'assigned_by' => $assignedBy,
            'assigned_at' => now(),
        ]);

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $assignedBy,
            'action' => 'task_reassigned',
            'entity_type' => 'close_task',
            'entity_id' => $taskId,
            'before' => ['assigned_to' => $previousAssignee],
            'after' => ['assigned_to' => $assignedTo],
        ]);

        return true;
    }

    /**
     * Get assignment history for a task
     */
    public function getAssignmentHistory(string $tenantId, string $taskId): array
    {
        return TaskAssignment::where('tenant_id', $tenantId)
            ->where('task_id', $taskId)
            ->orderBy('assigned_at', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Get manager board -- all tasks grouped by status
     */
    public function getManagerBoard(string $tenantId, string $cycleId): array
    {
        $tasks = CloseTask::where('tenant_id', $tenantId)
            ->where('cycle_id', $cycleId)
            ->get();

        return [
            'open' => $tasks->whereIn('status', ['created', 'assigned', 'in_progress'])->values()->toArray(),
            'blocked' => $tasks->where('status', 'blocked')->values()->toArray(),
            'overdue' => $tasks->where('status', '!=', 'completed')
                ->where('due_date', '<', now())
                ->values()
                ->toArray(),
            'completed' => $tasks->where('status', 'completed')->values()->toArray(),
        ];
    }
}