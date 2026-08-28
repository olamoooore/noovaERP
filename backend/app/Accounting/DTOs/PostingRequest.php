<?php

namespace App\Accounting\DTOs;

class PostingRequest
{
    /**
     * @param  PostingRequestLine[]  $lines
     */
    public function __construct(
        public readonly string $documentType,
        public readonly ?int $documentId,
        public readonly string $postingDate,
        public readonly string $description,
        public readonly array $lines,
        public readonly ?string $reference = null,
        public readonly ?string $currencyCode = null,
        public readonly ?float $exchangeRate = null,
        public readonly ?int $journalId = null,
        public readonly ?int $userId = null,
        public readonly ?string $overrideReason = null,
        public readonly ?int $approvedBy = null,
        public readonly array $metadata = [],
        public readonly ?int $legalEntityId = null,
        public readonly ?int $entityBookId = null,
        public readonly ?string $requestId = null,
        public readonly ?string $correlationId = null,
        public readonly ?int $tenantId = null,
        // ── Authority gate context (P0-1, ADR-005 §8) ──
        public readonly ?string $requiredPermission = null,
        public readonly ?string $requiredEntitlement = null,
        public readonly ?string $sourceCapability = null,
        // ── Tax determination context (P0-6, ACC-009) ──
        public readonly ?array $taxDeterminationResult = null,
        // ── Concurrency context (P1-8, ADR-005 §8.5) ──
        // Optimistic locking on the source aggregate. When set, ConcurrencyGate
        // will BLOCK if the current source aggregate version differs from
        // expectedRecordVersion at commit time.
        public readonly ?int $expectedRecordVersion = null,
        public readonly ?int $currentRecordVersion = null,
    ) {}
}
