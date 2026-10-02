<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentApplication;
use Illuminate\Support\Facades\DB;

/**
 * Issue #155: allocate a single payment across multiple invoices (or leave it
 * as unapplied credit on account). Applications are recorded explicitly in
 * payment_applications, and each applied amount flows through the canonical
 * InvoiceService settlement path so paid_amount/balance/status stay correct.
 */
class PaymentAllocationService
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
    ) {}

    /**
     * @param  array<int, array{invoice_id: int, amount: float}>  $applications
     */
    public function allocate(Payment $payment, array $applications): Payment
    {
        // Issue #155 (hostile re-review): only a cash-bearing, applied payment
        // may settle AR. Reversed/refunded/unapplied payments and unposted
        // drafts carry no cash.
        if ($payment->payment_status !== 'applied') {
            throw new \InvalidArgumentException(
                "Payment {$payment->payment_number} is '{$payment->payment_status}' — only applied payments can be allocated."
            );
        }

        return DB::transaction(function () use ($payment, $applications) {
            // Hostile re-review C-3 (#155): lock the payment row so concurrent
            // allocation requests cannot both pass the availability check and
            // over-allocate the same cash.
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $payment->loadMissing('applications');

            $available = $this->unappliedAmount($payment);
            $total = round(array_sum(array_map(
                fn (array $application) => (float) $application['amount'],
                $applications,
            )), 2);

            if ($total <= 0) {
                throw new \InvalidArgumentException('The allocation total must be greater than zero.');
            }

            if ($total > $available + 0.01) {
                throw new \InvalidArgumentException(
                    "The allocation total ({$total}) exceeds the payment's unapplied amount ({$available})."
                );
            }

            $seenInvoices = [];

            foreach ($applications as $application) {
                $invoice = Invoice::findOrFail($application['invoice_id']);
                $amount = round((float) $application['amount'], 2);

                if ($amount <= 0) {
                    throw new \InvalidArgumentException('Each allocation amount must be greater than zero.');
                }

                if ((int) $invoice->customer_id !== (int) $payment->customer_id) {
                    throw new \InvalidArgumentException(
                        "Invoice {$invoice->invoice_number} does not belong to the payment's customer."
                    );
                }

                // Issue #155 (hostile re-review): only open posted receivables
                // can be settled — draft/unposted, cancelled and voided/
                // superseded invoices have no AR to apply cash against.
                if (! in_array((string) $invoice->lifecycle_status, ['posted', 'credited'], true)
                    || in_array((string) $invoice->status, ['draft', 'cancelled'], true)) {
                    throw new \InvalidArgumentException(
                        "Invoice {$invoice->invoice_number} is not an open posted receivable "
                        ."(lifecycle '{$invoice->lifecycle_status}', status '{$invoice->status}')."
                    );
                }

                if (in_array($invoice->id, $seenInvoices, true)) {
                    throw new \InvalidArgumentException(
                        "Invoice {$invoice->invoice_number} appears more than once in the allocation."
                    );
                }
                $seenInvoices[] = $invoice->id;

                if (PaymentApplication::where('payment_id', $payment->id)
                    ->where('invoice_id', $invoice->id)
                    ->whereNull('reversed_at')
                    ->exists()) {
                    throw new \InvalidArgumentException(
                        "Invoice {$invoice->invoice_number} has already been allocated from this payment."
                    );
                }

                if ($amount > (float) $invoice->balance + 0.01) {
                    throw new \InvalidArgumentException(
                        "The allocation ({$amount}) exceeds the outstanding balance of invoice {$invoice->invoice_number}."
                    );
                }

                $this->invoiceService->applyAllocatedPayment($invoice, $amount, $payment);
            }

            return $payment->fresh(['applications.invoice']);
        });
    }

    /**
     * The portion of the payment not yet applied to any invoice.
     */
    public function unappliedAmount(Payment $payment): float
    {
        $applied = (float) $payment->applications()->whereNull('reversed_at')->sum('applied_amount');

        // #478: a cash discount is part of the payment's settlement capacity,
        // so it must count when computing what is still unapplied.
        $capacity = (float) $payment->amount + (float) ($payment->discount_amount ?? 0);

        return round($capacity - $applied, 2);
    }

    /**
     * Hostile re-review C-3 (#155): undo every live allocation of a payment —
     * restore each invoice's balance through the canonical settlement path and
     * mark the application reversed (audit preserved). Called by the payment
     * correction flows so reversing/refunding a split payment cannot leave the
     * other invoices marked paid.
     */
    public function reverseAllocations(Payment $payment): void
    {
        $payment->loadMissing('applications.invoice');

        foreach ($payment->applications->whereNull('reversed_at') as $application) {
            $invoice = $application->invoice;

            if ($invoice) {
                // Decrement the invoice (mirror of applying the allocation).
                // Using restoreRefundToInvoice here added the amount back —
                // reversing a receipt INCREASED paid_amount.
                $this->invoiceService->reverseAllocatedPayment(
                    $invoice,
                    (float) $application->applied_amount,
                    $payment,
                );
            }

            $application->update(['reversed_at' => now()]);
        }
    }
}
