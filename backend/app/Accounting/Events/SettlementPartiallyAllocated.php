<?php

namespace App\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SettlementPartiallyAllocated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $settlementId,
        public readonly float $allocatedAmount,
        public readonly float $remainingUnapplied,
        public readonly ?string $correlationId = null,
        public readonly ?string $tenantId = null,
    ) {}
}
