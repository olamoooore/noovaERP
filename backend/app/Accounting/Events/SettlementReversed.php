<?php

namespace App\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SettlementReversed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $settlementId,
        public readonly float $reversedAmount,
        public readonly ?string $reason = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $tenantId = null,
    ) {}
}
