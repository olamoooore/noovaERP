<?php

namespace App\Accounting\Events;

use App\Models\Bill;
use Illuminate\Foundation\Events\Dispatchable;

class BillPosted
{
    use Dispatchable;

    public function __construct(
        public readonly Bill $bill
    ) {}
}
