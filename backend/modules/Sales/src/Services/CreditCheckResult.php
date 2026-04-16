<?php

namespace Modules\Sales\Services;

class CreditCheckResult
{
    public function __construct(
        public bool $approved,
        public string $reason,
        public float $creditLimit,
        public float $currentBalance,
        public ?float $availableCredit,
        public float $orderTotal,
    ) {}
}