<?php

namespace App\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentProcessing
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $paymentExecutionId,
        public readonly ?string $providerReference = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $tenantId = null,
    ) {}
}
