<?php

namespace App\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an auto-reversal instruction is scheduled.
 * ADR-005 §15.3.10 — AutoReversalScheduled event.
 */
class AutoReversalScheduled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $instructionId,
        public readonly int $originalJournalEntryId,
        public readonly string $scheduledDate,
        public readonly string $reasonCode,
        public readonly ?string $correlationId = null,
        public readonly ?string $tenantId = null,
    ) {}
}
