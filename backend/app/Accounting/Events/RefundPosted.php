<?php

namespace App\Accounting\Events;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * #816 (EP5): money returned to the buyer is a MyInvois refund note (type 04),
 * a statutory document of its own — separate from the type-02 credit note,
 * which only reduces what is owed. Without this event a tenant with
 * e-invoicing enabled and auto_submit on never reported refunds, so the buyer's
 * money left the system with no document behind it.
 *
 * Dispatched AFTER the refund transaction commits, mirroring InvoicePosted /
 * CreditNotePosted. Only fired for customer (invoice) refunds — vendor refunds
 * are the self-billed refund note (type 14), tracked with #814 / #816 AC3.
 */
class RefundPosted
{
    use Dispatchable;

    public function __construct(
        public readonly Payment $refundPayment,
        public readonly Invoice $invoice,
        /**
         * The tenant the refund was issued under. Captured before the refund
         * work runs and carried on the event, because the posting path can tear
         * the tenancy context down before the post-commit dispatch — a listener
         * that read tenancy() afterwards would find no tenant and silently skip
         * the submission.
         */
        public readonly ?string $tenantId = null,
    ) {}
}
