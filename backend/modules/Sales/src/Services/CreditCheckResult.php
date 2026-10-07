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

    /**
     * Issue #326: snake_case shape used by the invoice API (create, post,
     * pre-flight, customer context strip).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'approved' => $this->approved,
            'reason' => $this->reason,
            'credit_limit' => $this->creditLimit,
            'current_balance' => $this->currentBalance,
            'available_credit' => $this->availableCredit,
            'document_total' => $this->orderTotal,
        ];
    }
}
