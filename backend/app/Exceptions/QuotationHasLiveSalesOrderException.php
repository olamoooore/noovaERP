<?php

namespace App\Exceptions;

/**
 * Issue #336 (Decision 7 Oct 2026): a confirmed quotation with a live sales
 * order cannot be rejected/revised — the operator must cancel the sales order
 * first. Controllers map this to a 422 with the message.
 */
class QuotationHasLiveSalesOrderException extends \RuntimeException
{
    public function __construct(string $message = 'This quotation is confirmed and has a live sales order. Cancel the sales order first, then reject or revise the quotation.')
    {
        parent::__construct($message);
    }
}
