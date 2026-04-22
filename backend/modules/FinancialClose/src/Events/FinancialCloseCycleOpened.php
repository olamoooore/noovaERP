<?php

namespace Modules\FinancialClose\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a close cycle transitions to 'opened'.
 * Listener: TriggerTaskGenerationOnCycleOpened
 */
class FinancialCloseCycleOpened
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $cycleId,
        public readonly string $entityId,
        public readonly string $periodId,
        public readonly string $userId,
    ) {}
}
