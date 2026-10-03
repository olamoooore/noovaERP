<?php

namespace App\Accounting\Events;

use App\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

class InvoicePosted
{
    use Dispatchable;

    public function __construct(
        public readonly Invoice $invoice,
        /**
         * Issue #902: the tenant the invoice was posted under. Captured before
         * the posting work runs and carried on the event, because a sync BI
         * job dispatched mid-posting tears the tenancy context down before
         * the post-commit dispatch — a listener reading tenancy() afterwards
         * would find no tenant and silently skip the e-invoice auto-submit
         * (the same teardown CreditNotePosted already carries a tenant for).
         */
        public readonly ?string $tenantId = null,
    ) {}
}
