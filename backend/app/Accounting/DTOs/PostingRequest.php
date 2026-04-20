<?php

namespace App\Accounting\DTOs;

use Carbon\Carbon;

class PostingRequest
{
    /**
     * @param PostingRequestLine[] $lines
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
    ) {}
}
