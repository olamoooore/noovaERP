<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Models\CloseTask;
use Modules\FinancialClose\Models\TaskDependency;
use Modules\FinancialClose\Models\AuditLog;

class FinancialCloseDependencyService
{
    /**
     * Get all dependencies for a task
     */
    public function getDependencies(string $tenantId, string $taskId): array
    {
        return TaskDependency::where('tenant_id', $tenantId)
            ->where('task_id', $taskId)
            ->get()
            ->toArray();
    }

    /**
     * Create a dependency between two tasks
     */
    public function createDependency(string $tenantId, string $taskId, string $dependsOnTaskId, string $type = 'hard'): ?TaskDependency
    {
        // Prevent self-dependency
        if ($taskId === $dependsOnTaskId) {
            Log::warning('FinancialClose: self-dependency not allowed', [
                'task_id' => $taskId,
            ]);
            return null;
        }

        $existing = TaskDependency::where('tenant_id', $tenantId)
            ->where('task_id', $taskId)
            ->where('depends_on_task_id', $dependsOnTaskId)
            ->first();

        if ($existing) {
            return $existing;
        }

        return TaskDependency::create([
            'tenant_id' => $tenantId,
            'task_id' => $taskId,
            'depends_on_task_id' => $dependsOnTaskId,
            'dependency_type' => $type,
        ]);
    }

    /**
     * Check if a task can be completed based on its dependencies
     * Hard dependencies must all be completed before the task can complete
     */
    public function canComplete(string $tenantId, string $taskId): bool
    {
        $hardDependencies = TaskDependency::where('tenant_id', $tenantId)
            ->where('task_id', $taskId)
            ->where('dependency_type', 'hard')
            ->get();

        foreach ($hardDependencies as $dependency) {
            $dependsOnTask = CloseTask::where('tenant_id', $tenantId)
                ->where('id', $dependency->depends_on_task_id)
                ->first();

            if (!$dependsOnTask || $dependsOnTask->status !== 'completed') {
                Log::warning('FinancialClose: hard dependency unresolved, completion denied', [
                    'task_id' => $taskId,
                    'depends_on_task_id' => $dependency->depends_on_task_id,
                    'depends_on_status' => $dependsOnTask?->status,
                ]);
                return false;
            }
        }

        return true;
    }

    /**
     * Get all blocked downstream tasks when a task is blocked
     */
    public function getBlockedDownstream(string $tenantId, string $taskId): array
    {
        return TaskDependency::where('tenant_id', $tenantId)
            ->where('depends_on_task_id', $taskId)
            ->where('dependency_type', 'hard')
            ->get()
            ->toArray();
    }

    /**
     * Check if all critical tasks in a cycle are completed
     * ready_to_close cannot ignore critical open tasks
     */
    public function allCriticalTasksCompleted(string $tenantId, string $cycleId): bool
    {
        $openCriticalTasks = CloseTask::where('tenant_id', $tenantId)
            ->where('cycle_id', $cycleId)
            ->where('is_critical', true)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->count();

        return $openCriticalTasks === 0;
    }
}