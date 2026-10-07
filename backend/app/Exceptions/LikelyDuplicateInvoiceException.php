<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Issue #327: thrown inside the invoice-create transaction when the new
 * invoice is a likely duplicate the user has not confirmed, so the creation
 * rolls back. Carries the InvoiceDuplicateCheck result for the response.
 */
class LikelyDuplicateInvoiceException extends RuntimeException
{
    /** @param array{level: string, matches: list<array<string, mixed>>, confirmed: bool} $result */
    public function __construct(public readonly array $result, string $message)
    {
        parent::__construct($message);
    }
}
