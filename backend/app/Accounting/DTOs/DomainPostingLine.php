<?php

namespace App\Accounting\DTOs;

/**
 * A single business-value line for a DomainPostingRequest.
 *
 * Operational capabilities submit account ROLE + amount + optional tax
 * evidence; PostingPolicyMapper resolves the debit/credit direction from
 * the versioned posting profile (ADR-005 §12). PSR-4: this class must live
 * in its own file so it can be referenced without also loading
 * DomainPostingRequest first.
 */
class DomainPostingLine
{
    public function __construct(
        public readonly string $accountRole,
        public readonly float $amount,
        public readonly ?string $currencyCode = null,
        public readonly ?string $description = null,
        public readonly array $dimensions = [],
        public readonly ?string $taxResultReference = null,
        // Line-level tax evidence carried through to JournalEntryLine
        // (parity with the legacy PostingRequestLine taxCode/taxId path
        // persisted at PostingEngine::post()).
        public readonly ?string $taxCode = null,
        public readonly ?int $taxId = null,
    ) {}
}
