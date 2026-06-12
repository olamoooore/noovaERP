<?php

namespace Modules\FinancialClose\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\FinancialClose\Events\FinancialCloseTaskCompleted;
use Modules\FinancialClose\Jobs\GenerateCompletionSnapshotJob;

/**
 * On task completion, dispatch an async completion snapshot generation for the cycle.
 * Each snapshot is append-only; callers always read the latest.
 */
class TriggerCompletionSnapshotOnTaskCompletion implements ShouldQueue
{
    public function handle(FinancialCloseTaskCompleted $event): void
    {
        tenancy()->initialize($event->tenantId);

        GenerateCompletionSnapshotJob::dispatch(
            $event->tenantId,
            $event->cycleId,
        );
    }
}
