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
        public readonly string $currencyCode = 'USD',
        public readonly ?float $exchangeRate = null,
        public readonly ?string $taxResultReference = null,
        public readonly array $dimensions = [],
        public readonly ?string $approvalReference = null,
        public readonly ?string $workflowInstanceId = null,
        public readonly ?string $authorityEvaluationId = null,
        public readonly array $supportingEvidenceRefs = [],
        public readonly ?string $createdBy = null,
    ) {}

    public static function generateRequestId(): string
    {
        return 'DPR-' . uniqid('', true);
    }
}

/**
 * A business value line for posting policy resolution.
 * NOT a debit/credit line — the PostingPolicyMapper resolves these into ledger lines.
 */
class DomainPostingLine
{
    public function __construct(
        public readonly string $accountRole,
        public readonly float $amount,
        public readonly string $currencyCode = 'USD',
        public readonly ?string $description = null,
        public readonly array $dimensions = [],
        public readonly ?string $taxResultReference = null,
    ) {}
}
