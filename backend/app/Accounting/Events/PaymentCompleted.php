<?php

namespace App\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $paymentExecutionId,
        public readonly int $paymentId,
        public readonly float $amount,
        public readonly ?string $providerReference = null,
        public readonly ?string $settlementReference = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $tenantId = null,
    ) {}
}
