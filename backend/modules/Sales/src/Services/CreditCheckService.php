<?php

namespace Modules\Sales\Services;

use App\Models\Customer;

class CreditCheckService
{
    /**
     * @param  float  $committedOrdersTotal  Issue #240: sum of the customer's
     *   other confirmed-but-not-yet-invoiced orders. Those orders do not touch
     *   total_outstanding until invoiced, so without them several individually
     *   within-limit confirmations could cumulatively blow through the limit.
     */
    public function checkCreditLimit(Customer $customer, float $orderTotal, float $committedOrdersTotal = 0.0): CreditCheckResult
    {
        $creditLimit = $customer->credit_limit;
        $currentBalance = (float) ($customer->total_outstanding ?? 0);
        $exposure = $currentBalance + max(0.0, $committedOrdersTotal);

        // Issue #144: NULL means no credit control configured (explicit
        // unlimited); 0 means exactly zero credit and must block. Previously
        // both were treated as unlimited, which gave every customer created
        // without an explicit limit unlimited credit by default.
        if ($creditLimit === null) {
            return new CreditCheckResult(
                approved: true,
                reason: 'No credit limit configured (unlimited)',
                creditLimit: 0.0,
                currentBalance: $currentBalance,
                availableCredit: null,
                orderTotal: $orderTotal,
            );
        }

        $creditLimit = (float) $creditLimit;
        $availableCredit = $creditLimit - $exposure;

        if ($creditLimit <= 0.0) {
            return new CreditCheckResult(
                approved: false,
                reason: 'Credit limit is zero — prepayment is required before this order can be confirmed',
                creditLimit: 0.0,
                currentBalance: $currentBalance,
                availableCredit: min(0.0, $availableCredit),
                orderTotal: $orderTotal,
            );
        }

        $approved = ($exposure + $orderTotal) <= $creditLimit;

        return new CreditCheckResult(
            approved: $approved,
            reason: $approved
                ? 'Within credit limit'
                : "Order total {$orderTotal} would exceed available credit {$availableCredit}",
            creditLimit: $creditLimit,
            currentBalance: $currentBalance,
            availableCredit: $availableCredit,
            orderTotal: $orderTotal,
        );
    }
}
