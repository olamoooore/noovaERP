<?php

namespace App\Accounting\DTOs;

/**
 * Request DTO for posting an invoice via the accounting-origin path.
 * Wraps the invoice ID and any overrides the accountant may provide.
 */
class PostInvoiceRequest
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly ?string $postingDate = null,
        public readonly ?int $userId = null,
    ) {}
}
