<?php

namespace App\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SettlementAllocated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $settlementId,
        public readonly int $allocationId,
        public readonly float $allocatedAmount,
        public readonly string $sourceRecordType,
        public readonly int $sourceRecordId,
        public readonly ?string $correlationId = null,
        public readonly ?string $tenantId = null,
    ) {}
}
