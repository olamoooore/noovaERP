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
        return DB::transaction(function () use ($payment, $applications) {
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

                if (in_array($invoice->id, $seenInvoices, true)) {
                    throw new \InvalidArgumentException(
                        "Invoice {$invoice->invoice_number} appears more than once in the allocation."
                    );
                }
                $seenInvoices[] = $invoice->id;

                if (PaymentApplication::where('payment_id', $payment->id)
                    ->where('invoice_id', $invoice->id)
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
        $applied = (float) $payment->applications()->sum('applied_amount');

        return round((float) $payment->amount - $applied, 2);
    }
}
