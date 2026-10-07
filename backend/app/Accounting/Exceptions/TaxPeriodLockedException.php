<?php

namespace App\Accounting\Exceptions;

use App\Accounting\Enums\ReasonCode;

/**
 * #278 (F4): a tax-relevant posting was refused because its tax period is
 * closed (filed or explicitly closed). Callers map it to HTTP 422 with the
 * canonical reason code; the posting has no ledger effect.
 */
class TaxPeriodLockedException extends \RuntimeException
{
    public const REASON_CODE = ReasonCode::TAX_PERIOD_LOCKED->value;
}
