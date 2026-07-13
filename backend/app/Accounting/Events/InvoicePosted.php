<?php

namespace App\Accounting\Events;

use App\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

class InvoicePosted
{
    use Dispatchable;

    public function __construct(
        public readonly Invoice $invoice
    ) {}
}
