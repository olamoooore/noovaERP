<?php

namespace Modules\FinancialClose\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\FinancialClose\Events\FinancialCloseCycleOpened;
use Modules\FinancialClose\Jobs\GenerateTasksFromChecklistJob;
use Illuminate\Support\Facades\Log;

/**
 * On cycle open, dispatch task generation from the linked checklist template.
 * If no template is configured on the cycle, the Job exits cleanly.
 */
class TriggerTaskGenerationOnCycleOpened implements ShouldQueue
{
    public function handle(FinancialCloseCycleOpened $event): void
    {
        tenancy()->initialize($event->tenantId);

        Log::info('FinancialClose: TriggerTaskGenerationOnCycleOpened dispatching GenerateTasksFromChecklistJob', [
            'tenant_id' => $event->tenantId,
            'cycle_id'  => $event->cycleId,
        ]);

        GenerateTasksFromChecklistJob::dispatch(
            $event->tenantId,
            $event->cycleId,
            '',
            $event->userId,
        );
    }
}
