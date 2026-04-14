<?php

namespace Modules\Sales\Services;

use App\Models\Customer;

class CreditCheckService
{
    public function checkCreditLimit(Customer $customer, float $orderTotal): CreditCheckResult
    {
        $creditLimit = (float) ($customer->credit_limit ?? 0);
        $currentBalance = (float) ($customer->total_outstanding ?? 0);

        if ($creditLimit <= 0) {
            return new CreditCheckResult(
                approved: true,
                reason: 'No credit limit configured (unlimited)',
                creditLimit: $creditLimit,
                currentBalance: $currentBalance,
                availableCredit: null,
                orderTotal: $orderTotal,
            );
        }

        $availableCredit = $creditLimit - $currentBalance;
        $approved = ($currentBalance + $orderTotal) <= $creditLimit;

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