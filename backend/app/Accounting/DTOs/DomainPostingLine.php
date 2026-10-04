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
        // MRD pricelist-multicurrency FR-5.7/§12: the line's ORIGINAL
        // (document-currency) amount and the document snapshot rate. The
        // GL debit/credit stays BASE; these columns carry the foreign side
        // for dual display and revaluation — assembled from the document
        // snapshot, never recomputed.
        public readonly ?float $originalAmount = null,
        public readonly ?float $exchangeRate = null,
        public readonly ?string $description = null,
        public readonly array $dimensions = [],
        public readonly ?string $taxResultReference = null,
        // Line-level tax evidence carried through to JournalEntryLine
        // (parity with the legacy PostingRequestLine taxCode/taxId path
        // persisted at PostingEngine::post()).
        public readonly ?string $taxCode = null,
        public readonly ?int $taxId = null,
        // Issue #333: an explicit account pinned by the caller (the tenant's
        // cash-rounding account) with its own debit/credit direction. The
        // posting profiles express direction per role, which cannot represent
        // a sign-dependent rounding leg; an explicit account + direction is
        // only valid when BOTH are supplied.
        //
        // Issue #876 (SA2): an account WITHOUT a direction pins only the
        // CONCRETE account (a document line's income/GL override) — the
        // profile role still owns the direction and validation; only the
        // resolved account changes. Null keeps the role-based resolution
        // exactly as before.
        public readonly ?int $accountId = null,
        public readonly ?string $direction = null,
    ) {}
}
