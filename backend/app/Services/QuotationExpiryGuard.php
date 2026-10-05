<?php

namespace App\Services;

use App\Exceptions\QuotationExpiredException;
use App\Models\Quotation;

/**
 * Issue #335 (Q6): the single validity guard for quotations.
 *
 * `valid_until` is only meaningful while a quotation is still convertible: a
 * draft/sent/expired quotation whose validity date is before today must not be
 * confirmed, converted (invoice / delivery order) or accepted through the
 * customer portal. Re-validation means a new future `valid_until` (the edit
 * path also returns a command-expired quotation to draft through the #900
 * engine) or a replacement revision (#178).
 *
 * Confirmed quotations are exempt: their validity has already been accepted
 * and downstream documents may still be created from them.
 */
class QuotationExpiryGuard
{
    /**
     * The refusal reason, or null when the quotation is within validity.
     */
    public function check(Quotation $quotation): ?string
    {
        if (! $quotation->isExpired()) {
            return null;
        }

        $date = $quotation->valid_until?->toDateString() ?? 'its validity date';

        return "This quotation expired on {$date} and can no longer be confirmed, converted or accepted. "
            .'Set a new valid-until date (or create a revision) and try again.';
    }

    /**
     * @throws QuotationExpiredException
     */
    public function assertNotExpired(Quotation $quotation): void
    {
        $reason = $this->check($quotation);

        if ($reason !== null) {
            throw new QuotationExpiredException($reason, $quotation);
        }
    }
}
