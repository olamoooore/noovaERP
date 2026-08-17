<?php

namespace App\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentInitiated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $paymentExecutionId,
        public readonly int $paymentId,
        public readonly float $amount,
        public readonly string $currencyCode,
        public readonly string $method,
        public readonly ?string $correlationId = null,
        public readonly ?string $tenantId = null,
    ) {}
}
