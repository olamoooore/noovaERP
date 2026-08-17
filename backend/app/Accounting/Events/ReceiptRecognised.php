<?php

namespace App\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReceiptRecognised
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $settlementId,
        public readonly float $amount,
        public readonly string $sourceType,
        public readonly int $sourceId,
        public readonly ?string $correlationId = null,
        public readonly ?string $tenantId = null,
    ) {}
}
