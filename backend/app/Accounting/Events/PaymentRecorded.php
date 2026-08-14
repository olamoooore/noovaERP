<?php

namespace App\Accounting\Events;

use App\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

class PaymentRecorded
{
    use Dispatchable;

    public function __construct(
        public readonly Payment $payment
    ) {}
}
