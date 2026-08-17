<?php

namespace App\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a reversal is requested but before it is posted.
 * ADR-005 §15.3.10 — ReversalRequested event.
 */
class ReversalRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $reversalRelationshipId,
        public readonly int $originalJournalEntryId,
        public readonly string $reversalMode,
        public readonly string $reversalBasis,
        public readonly string $reasonCode,
        public readonly ?string $reasonText = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $tenantId = null,
    ) {}
}
