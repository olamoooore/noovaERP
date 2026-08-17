<?php

namespace App\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a scheduled auto-reversal is successfully posted.
 * ADR-005 §15.3.10 — AutoReversalPosted event.
 */
class AutoReversalPosted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $instructionId,
        public readonly int $originalJournalEntryId,
        public readonly int $reversalJournalEntryId,
        public readonly float $reversedAmount,
        public readonly ?string $correlationId = null,
        public readonly ?string $tenantId = null,
    ) {}
}
