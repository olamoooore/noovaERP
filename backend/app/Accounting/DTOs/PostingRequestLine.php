<?php

namespace App\Accounting\DTOs;

class PostingRequestLine
{
    public function __construct(
        public readonly string $accountMappingKey,
        public readonly ?int $accountId,
        public readonly float $debit,
        public readonly float $credit,
        public readonly ?string $description = null,
        public readonly ?string $reference = null,
        public readonly ?int $departmentId = null,
        public readonly ?int $projectId = null,
        public readonly ?int $locationId = null,
        public readonly ?string $currencyCode = null,
        public readonly ?float $originalAmount = null,
        public readonly ?float $exchangeRate = null,
        // TASK TAX-001: Add tax_code for tax audit trail
        public readonly ?string $taxCode = null,
        public readonly ?int $taxId = null,
    ) {}
}
