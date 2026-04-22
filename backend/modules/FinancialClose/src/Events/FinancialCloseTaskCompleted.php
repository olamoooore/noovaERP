<?php

namespace Modules\FinancialClose\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a task is successfully completed (dependency gate passed).
 * Listeners: TriggerReadinessRefreshOnTaskCompletion,
 *            TriggerCompletionSnapshotOnTaskCompletion,
 *            TriggerUnblockDownstreamOnTaskCompletion
 */
class FinancialCloseTaskCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $taskId,
        public readonly string $userId,
        public readonly string $cycleId,
    ) {}
}
