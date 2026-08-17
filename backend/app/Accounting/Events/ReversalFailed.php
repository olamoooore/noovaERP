<?php

namespace App\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a reversal attempt fails.
 * ADR-005 §15.3.10 — ReversalFailed event.
 */
class ReversalFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $originalJournalEntryId,
        public readonly string $reasonCode,
        public readonly string $failureReason,
        public readonly ?string $correlationId = null,
        public readonly ?string $tenantId = null,
    ) {}
}
