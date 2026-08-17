<?php

namespace App\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentSubmissionUnknown
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $paymentExecutionId,
        public readonly string $reason,
        public readonly ?string $correlationId = null,
        public readonly ?string $tenantId = null,
    ) {}
}
