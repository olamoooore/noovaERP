<?php

namespace App\Exceptions;

/**
 * #539 / #489: a receipt would leave an invoice partly paid while the sales
 * setting sales.invoice.allow_partial_payments is off.
 */
class PartialPaymentNotAllowedException extends \InvalidArgumentException {}
