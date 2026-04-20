<?php

namespace App\Accounting\DTOs;

/**
 * Request DTO for creating an accounting-origin bill.
 * Used by AccountingOriginBillService for manual AP bills
 * created directly by accounting/admin users.
 */
class AccountingOriginBillData
{
    /**
     * @param int $vendorId
     * @param string $billType expense|procurement
     * @param AccountingDocumentLineData[] $lines
     * @param string|null $billDate
     * @param string|null $dueDate
     * @param string|null $notes
     * @param string|null $terms
     * @param string|null $vendorReference
     * @param string|null $internalReference
     * @param float $discountAmount
     * @param bool $autoPost Whether to auto-post after creation
     * @param array $originContext Freeform metadata bag
     * @param string|null $createdViaRole Role of the creating user
     */
    public function __construct(
        public readonly int $vendorId,
        public readonly string $billType,
        public readonly array $lines,
        public readonly ?string $billDate = null,
        public readonly ?string $dueDate = null,
        public readonly ?string $notes = null,
        public readonly ?string $terms = null,
        public readonly ?string $vendorReference = null,
        public readonly ?string $internalReference = null,
        public readonly float $discountAmount = 0,
        public readonly bool $autoPost = false,
        public readonly array $originContext = [],
        public readonly ?string $createdViaRole = null,
    ) {}
}
