<?php

namespace App\Exceptions;

use App\Models\Quotation;

/**
 * Issue #335 (Q6): raised when a quotation whose validity date has passed is
 * confirmed, converted or accepted. Controllers map it to a 422 with the
 * message so the operator is told to re-validate instead of silently
 * converting a stale price.
 */
class QuotationExpiredException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?Quotation $quotation = null,
    ) {
        parent::__construct($message);
    }
}
