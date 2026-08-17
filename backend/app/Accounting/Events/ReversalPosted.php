<?php

namespace App\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a reversal is successfully posted to the ledger.
 * ADR-005 §15.3.10 — ReversalPosted event.
 */
class ReversalPosted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $reversalRelationshipId,
        public readonly int $originalJournalEntryId,
        public readonly int $reversalJournalEntryId,
        public readonly string $reversalMode,
        public readonly float $reversedAmount,
        public readonly ?string $correctionCaseId = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $tenantId = null,
    ) {}
}
