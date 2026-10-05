<?php

declare(strict_types=1);

namespace Modules\Sales\Exceptions;

use App\Models\Customer;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\CreditCheckResult;
use RuntimeException;

/**
 * CreditLimitExceededException — thrown by SalesOrderService::confirmOrder when
 * the customer's credit check rejects the order (issue #903, PC9).
 *
 * Typed so the route boundary can notify the account owner after the failing
 * transaction has rolled back, while the message stays exactly what the
 * existing generic `\Exception` path produced.
 */
class CreditLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly CreditCheckResult $creditCheck,
        public readonly ?Customer $customer = null,
        public readonly ?SalesOrder $order = null,
    ) {
        parent::__construct('Credit check failed: '.$creditCheck->reason);
    }
}
