<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Models\AuditLog;
use Modules\FinancialClose\Models\CloseTask;
use Modules\FinancialClose\Models\TaskDependency;

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
     * Create a dependency between two tasks.
     * Guards: self-dependency, duplicate, circular path.
     */
    public function createDependency(string $tenantId, string $taskId, string $dependsOnTaskId, string $type = 'hard'): ?TaskDependency
    {
        if ($taskId === $dependsOnTaskId) {
            Log::warning('FinancialClose: self-dependency not allowed', ['task_id' => $taskId]);

            return null;
        }

        // Circular detection: if $dependsOnTaskId already (transitively) depends on $taskId, adding this edge would create a cycle
        if ($this->wouldCreateCycle($tenantId, $taskId, $dependsOnTaskId)) {
            Log::warning('FinancialClose: circular dependency detected, rejected', [
                'task_id' => $taskId,
                'depends_on_task_id' => $dependsOnTaskId,
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
     * Check whether adding taskId -> dependsOnTaskId would create a cycle.
     * Traverses the existing graph from dependsOnTaskId's own dependencies.
     * If taskId is reachable from dependsOnTaskId, adding the edge would cycle.
     */
    protected function wouldCreateCycle(string $tenantId, string $taskId, string $dependsOnTaskId): bool
    {
        $visited = [];
        $queue = [$dependsOnTaskId];

        while (! empty($queue)) {
            $current = array_shift($queue);

            if ($current === $taskId) {
                return true;
            }

            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;

            $upstreamIds = TaskDependency::where('tenant_id', $tenantId)
                ->where('task_id', $current)
                ->pluck('depends_on_task_id')
                ->toArray();

            foreach ($upstreamIds as $id) {
                if (! isset($visited[$id])) {
                    $queue[] = $id;
                }
            }
        }

        return false;
    }

    /**
     * Check if a task can be completed based on its dependencies.
     * All hard dependencies must be completed — no exceptions.
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

            if (! $dependsOnTask || $dependsOnTask->status !== 'completed') {
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
     * Return structured list of unresolved hard dependencies for a task.
     * Used to surface blocker info to the caller/UI.
     */
    public function getUnresolvedHardDependencies(string $tenantId, string $taskId): array
    {
        return TaskDependency::where('tenant_id', $tenantId)
            ->where('task_id', $taskId)
            ->where('dependency_type', 'hard')
            ->with('dependsOnTask:id,title,status')
            ->get()
            ->filter(fn ($dep) => $dep->dependsOnTask?->status !== 'completed')
            ->map(fn ($dep) => [
                'depends_on_task_id' => $dep->depends_on_task_id,
                'depends_on_task_title' => $dep->dependsOnTask?->title,
                'depends_on_status' => $dep->dependsOnTask?->status,
            ])
            ->values()
            ->toArray();
    }

    /**
     * After a task is completed, unblock any downstream tasks that were blocked
     * solely because this task was an unresolved hard dependency.
     */
    public function unblockDownstreamTasks(string $tenantId, string $completedTaskId): int
    {
        $downstreamDeps = TaskDependency::where('tenant_id', $tenantId)
            ->where('depends_on_task_id', $completedTaskId)
            ->where('dependency_type', 'hard')
            ->get();

        $unblocked = 0;

        foreach ($downstreamDeps as $dep) {
            $downstreamTask = CloseTask::where('tenant_id', $tenantId)
                ->where('id', $dep->task_id)
                ->where('status', 'blocked')
                ->first();

            if (! $downstreamTask) {
                continue;
            }

            // Only unblock if ALL other hard deps are also now resolved
            if ($this->canComplete($tenantId, $downstreamTask->id)) {
                $downstreamTask->update(['status' => 'created']);

                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_id' => 'system',
                    'action' => 'task_unblocked_by_dependency_completion',
                    'entity_type' => 'close_task',
                    'entity_id' => $downstreamTask->id,
                    'after' => ['unblocked_by' => $completedTaskId],
                ]);

                $unblocked++;
            }
        }

        Log::info('FinancialClose: downstream tasks unblocked after task completion', [
            'completed_task_id' => $completedTaskId,
            'unblocked_count' => $unblocked,
        ]);

        return $unblocked;
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
     * Check if all critical tasks in a cycle are completed.
     * ready_to_close cannot pass while critical tasks remain open.
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
