<?php

namespace App\Platform\Exceptions;

use App\Enums\UserRole;

/**
 * Issue #902 [SALES-PLAT] P2 (PC8): the catalogue of business-relevant failures
 * the Sales domain raises to the platform exception queue.
 *
 * Each case carries the routing metadata the operations console (#914) and the
 * queue UI need without a second lookup:
 *  - the owning module,
 *  - the default severity,
 *  - the owner role that has to resolve the exception (existing UserRole
 *    conventions — no new roles, no seeder changes),
 *  - the retry handler key when the operation is safely retryable.
 */
enum ExceptionType: string
{
    /**
     * A delivery order sat in `pending` but its approvers could not be told.
     */
    case SALES_DO_APPROVAL_NOTIFICATION_FAILED = 'sales.delivery_order.approval_notification_failed';

    /**
     * The tax the document booked differs from the tax engine's determination.
     * The GL stays booked-first (balance safe); an accountant must reconcile.
     */
    case SALES_INVOICE_TAX_VARIANCE = 'sales.invoice.tax_variance';

    /**
     * Posting absorbed an FX rounding residual at revenue beyond the accepted
     * tolerance. The entry balances, but the revenue line no longer matches
     * subtotal × rate — an accountant has to review the rate/rounding.
     */
    case SALES_INVOICE_FX_RESIDUAL = 'sales.invoice.fx_residual';

    /**
     * A posted invoice / credit note / refund note was not accepted by the
     * country e-invoicing provider during auto-submit.
     */
    case SALES_EINVOICE_AUTO_SUBMIT_FAILED = 'sales.einvoice.auto_submit_failed';

    /**
     * A payment could not be posted to the GL (seen in the bulk "mark paid"
     * flows, which report the row as skipped).
     */
    case SALES_PAYMENT_POSTING_FAILED = 'sales.payment.posting_failed';

    public function module(): string
    {
        return 'sales';
    }

    public function defaultSeverity(): string
    {
        return match ($this) {
            self::SALES_INVOICE_FX_RESIDUAL => 'medium',
            default => 'high',
        };
    }

    public function ownerRole(): UserRole
    {
        return match ($this) {
            self::SALES_DO_APPROVAL_NOTIFICATION_FAILED => UserRole::SALES,
            default => UserRole::ACCOUNTANT,
        };
    }

    public function retryHandler(): ?string
    {
        return match ($this) {
            self::SALES_DO_APPROVAL_NOTIFICATION_FAILED => 'sales.delivery_order.notify_approvers',
            self::SALES_EINVOICE_AUTO_SUBMIT_FAILED => 'compliance.einvoice.submit',
            self::SALES_PAYMENT_POSTING_FAILED => 'sales.payment.post',
            self::SALES_INVOICE_TAX_VARIANCE, self::SALES_INVOICE_FX_RESIDUAL => null,
        };
    }
}
