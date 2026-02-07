<?php

namespace Modules\PaymentGateway\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\PaymentGateway\Models\PaymentTransaction;

class PaymentSuccessful
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public PaymentTransaction $transaction,
        public array $metadata = []
    ) {}
}
