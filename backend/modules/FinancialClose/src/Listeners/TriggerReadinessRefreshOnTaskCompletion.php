<?php

namespace Modules\FinancialClose\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Events\FinancialCloseTaskCompleted;
use Modules\FinancialClose\Jobs\RefreshReadinessSnapshotJob;
use Modules\FinancialClose\Models\CloseTask;

/**
 * On task completion, dispatch an async readiness refresh for the affected entity/period.
 * Reads entity_id and period_id from the cycle attached to the task.
 */
class TriggerReadinessRefreshOnTaskCompletion implements ShouldQueue
{
    public function handle(FinancialCloseTaskCompleted $event): void
    {
        tenancy()->initialize($event->tenantId);

        $task = CloseTask::where('tenant_id', $event->tenantId)
            ->where('id', $event->taskId)
            ->with('cycle:id,entity_id,period_id')
            ->first();

        if (! $task || ! $task->cycle) {
            Log::warning('FinancialClose: TriggerReadinessRefreshOnTaskCompletion — task or cycle not found', [
                'task_id' => $event->taskId,
            ]);

            return;
        }

        RefreshReadinessSnapshotJob::dispatch(
            $event->tenantId,
            $task->cycle->entity_id,
            $task->cycle->period_id,
            $event->cycleId,
        );
    }
}
