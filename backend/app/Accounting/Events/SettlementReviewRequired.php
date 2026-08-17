<?php

namespace App\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SettlementReviewRequired
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $settlementId,
        public readonly string $reasonCode,
        public readonly ?string $reasonText = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $tenantId = null,
    ) {}
}
