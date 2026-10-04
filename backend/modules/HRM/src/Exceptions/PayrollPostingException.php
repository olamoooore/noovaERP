<?php

namespace Modules\HRM\Exceptions;

use RuntimeException;

/**
 * Thrown inside the payroll lifecycle transaction when the GL posting for an
 * approval (accrual) or a mark-paid (payment) fails, so the transaction rolls
 * back the status change instead of leaving the subledger lying about it.
 */
class PayrollPostingException extends RuntimeException {}
