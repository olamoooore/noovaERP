<?php

namespace App\Accounting\DTOs;

/**
 * Request DTO for creating an accounting-origin invoice.
 * Used by AccountingOriginInvoiceService for manual AR invoices
 * created directly by accounting/admin users (not via SalesOrder).
 */
class AccountingOriginInvoiceData
{
    /**
     * @param  AccountingDocumentLineData[]  $lines
     * @param  bool  $autoPost  Whether to auto-post after creation
     * @param  array  $originContext  Freeform metadata bag
     * @param  string|null  $createdViaRole  Role of the creating user
     */
    public function __construct(
        public readonly int $customerId,
        public readonly array $lines,
        public readonly ?string $invoiceDate = null,
        public readonly ?string $dueDate = null,
        public readonly ?string $notes = null,
        public readonly ?string $terms = null,
        public readonly ?string $customerReference = null,
        public readonly ?string $internalReference = null,
        public readonly float $discountAmount = 0,
        public readonly bool $autoPost = false,
        public readonly array $originContext = [],
        public readonly ?string $createdViaRole = null,
        // Issue #456: the source document's currency snapshot and order link
        // must travel with the conversion — a USD delivery order stays USD.
        public readonly ?string $currencyCode = null,
        public readonly ?string $baseCurrencyCode = null,
        public readonly ?float $exchangeRate = null,
        public readonly ?int $salesOrderId = null,
    ) {}
}
