<?php

namespace App\Accounting\Events;

use App\Models\CustomerCreditNote;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * #516 (CN7): a posted customer credit note must reach the e-invoicing
 * workflow exactly as a posted invoice does. For MyInvois a credit note is
 * type 02 and must reference the original invoice's UUID, so a credit note
 * that never leaves the system leaves the buyer's and seller's records
 * inconsistent.
 *
 * Dispatched AFTER the posting transaction commits, mirroring InvoicePosted.
 */
class CreditNotePosted
{
    use Dispatchable;

    public function __construct(
        public readonly CustomerCreditNote $creditNote,
        /**
         * The tenant the credit note was posted under. Captured before the
         * posting work runs and carried on the event, because the posting
         * path tears the tenancy context down before the post-commit
         * dispatch — a listener that read tenancy() afterwards would find no
         * tenant and silently skip submission.
         */
        public readonly ?string $tenantId = null,
    ) {}
}
