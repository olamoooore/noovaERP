<?php

namespace App\Accounting\DTOs;

/**
 * DomainPostingRequest — the controlled contract for operational capabilities
 * to request accounting effects through ACC-002.
 * ADR-005 §13 — operational domains may not submit unrestricted debit/credit lines.
 *
 * Input from operational domains (AR, AP, Assets, Tax, etc.) goes through
 * the PostingPolicyMapper which resolves posting rules into ledger lines.
 */
class DomainPostingRequest
{
    /**
     * @param  DomainPostingLine[]  $lines  Business values for posting policy resolution
     */
    public function __construct(
        public readonly string $postingRequestId,
        public readonly string $idempotencyKey,
        public readonly string $tenantId,
        public readonly string $entityId,
        public readonly string $accountingBookId,
        public readonly string $sourceCapability,
        public readonly string $sourceRecordType,
        public readonly string $sourceRecordId,
        public readonly int $sourceRecordVersion,
        public readonly string $postingProfileCode,
        public readonly string $transactionDate,
        public readonly string $postingDate,
        public readonly array $lines,
        public readonly ?string $currencyCode = null,
        public readonly ?float $exchangeRate = null,
        public readonly ?string $taxResultReference = null,
        public readonly array $dimensions = [],
        public readonly ?string $approvalReference = null,
        public readonly ?string $workflowInstanceId = null,
        public readonly ?string $authorityEvaluationId = null,
        public readonly array $supportingEvidenceRefs = [],
        public readonly ?string $createdBy = null,
        /**
         * Acting central user id forwarded to the inner PostingRequest so
         * PermissionGate/audit context resolves (ADR-005 §8 gate actor).
         * Required by operational migrations (ACC-RM-001) — domain postings
         * must not silently lose the actor the legacy path carried.
         */
        public readonly ?int $userId = null,
        /**
         * Canonical tax determination persisted with the journal
         * (Issue 42 contract). Passed through untouched to the inner
         * PostingRequest so migrating an operational service cannot drop
         * tax evidence.
         */
        public readonly ?array $taxDeterminationResult = null,
        /**
         * Optional ledger-entry description. When the migrating service
         * previously set a bespoke description on PostingRequest (e.g.
         * "Invoice #INV-1 - Acme Corp"), pass it here so the domain path
         * produces an identical journal_entries.description.
         */
        public readonly ?string $ledgerDescription = null,
    ) {}

    public static function generateRequestId(): string
    {
        // state_transitions.command_id is a UUID column and the engine binds
        // this value as command_id on the posting state transition
        // (found by the first runtime consumer of domainPosting — the
        // InvoiceService ACC-RM-001 migration). The id must therefore be a
        // UUID, not a prefixed uniqid.
        return (string) \Illuminate\Support\Str::uuid();
    }
}

/**
 * A business value line for posting policy resolution.
 * NOT a debit/credit line — the PostingPolicyMapper resolves these into ledger lines.
 */
// DomainPostingLine moved to its own PSR-4 file
// (app/Accounting/DTOs/DomainPostingLine.php) so it can be autoloaded
// independently of this DTO.
