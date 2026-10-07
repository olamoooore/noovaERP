<?php

namespace App\Services;

use App\Models\CustomerCreditNote;
use App\Models\CustomerCreditNoteApplication;
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

                // #495: cash received by one legal entity cannot settle
                // another entity's receivable.
                $this->assertSameEntity($payment->legal_entity_id, $invoice, "payment {$payment->payment_number}");

                // Issue #155 (hostile re-review): only open posted receivables
                // can be settled — draft/unposted, cancelled and voided/
                // superseded invoices have no AR to apply cash against.
                $this->assertOpenReceivable($invoice);

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

                // Issue #879 (SA6): a written-off invoice remains recoverable —
                // cash can reinstate the receivable up to the amount written
                // off — so the capacity is the open balance plus the
                // not-yet-recovered write-off.
                $recoverable = round((float) $invoice->balance + (float) $invoice->written_off_amount, 2);

                if ($amount > $recoverable + 0.01) {
                    throw new \InvalidArgumentException(
                        "The allocation ({$amount}) exceeds the outstanding balance of invoice {$invoice->invoice_number}."
                    );
                }

                $this->invoiceService->applyAllocatedPayment($invoice, $amount, $payment);
            }

            // ACC-005B: keep the settlement overlay in step with the
            // authoritative application ledger.
            app(SettlementRecognitionService::class)->recognisePayment($payment->fresh());

            return $payment->fresh(['applications.invoice']);
        });
    }

    /**
     * Issue #495 ("Outstanding credits"): apply a posted credit note's
     * remaining customer credit to other open invoices of the same customer,
     * legal entity and currency — full or partial. The credit moves between
     * the two receivables through InvoiceService (credited_amount), so each
     * balance stays total − paid − credited and the note's remaining credit
     * drops by the applied amount. Both receivables sit in the same AR
     * control account of the same entity, so the ledger is unchanged and no
     * journal is posted (as for a #155 payment allocation).
     *
     * @param  array<int, array{invoice_id: int, amount: float}>  $applications
     */
    public function allocateCreditNote(CustomerCreditNote $creditNote, array $applications): CustomerCreditNote
    {
        return DB::transaction(function () use ($creditNote, $applications) {
            // Lock the note so concurrent requests cannot both spend its credit.
            $creditNote = CustomerCreditNote::whereKey($creditNote->id)->lockForUpdate()->firstOrFail();

            if ($creditNote->status !== 'posted') {
                throw new \InvalidArgumentException(
                    "Credit note {$creditNote->credit_note_number} is '{$creditNote->status}' — only posted credit notes can be applied."
                );
            }

            $origin = Invoice::whereKey($creditNote->invoice_id)->lockForUpdate()->first();
            if (! $origin) {
                throw new \InvalidArgumentException("Credit note {$creditNote->credit_note_number} has no source invoice holding its credit.");
            }

            $available = app(CustomerCreditNoteOutcomeService::class)->remainingCredit($creditNote);
            $total = round(array_sum(array_map(
                fn (array $application) => (float) $application['amount'],
                $applications,
            )), 2);

            if ($total <= 0) {
                throw new \InvalidArgumentException('The application total must be greater than zero.');
            }

            if ($total > $available + 0.005) {
                throw new \InvalidArgumentException(
                    "The application total ({$total}) exceeds the credit note's remaining credit ({$available})."
                );
            }

            $currency = $creditNote->currency_code ?: $origin->currency_code;
            $seenInvoices = [];

            foreach ($applications as $application) {
                $invoice = Invoice::whereKey($application['invoice_id'])->lockForUpdate()->firstOrFail();
                $amount = round((float) $application['amount'], 2);

                if ($amount <= 0) {
                    throw new \InvalidArgumentException('Each application amount must be greater than zero.');
                }

                if ((int) $invoice->id === (int) $origin->id) {
                    throw new \InvalidArgumentException(
                        "Credit note {$creditNote->credit_note_number} already credits invoice {$invoice->invoice_number}."
                    );
                }

                if ((int) $invoice->customer_id !== (int) $creditNote->customer_id) {
                    throw new \InvalidArgumentException(
                        "Invoice {$invoice->invoice_number} does not belong to the credit note's customer."
                    );
                }

                $this->assertSameEntity($creditNote->legal_entity_id, $invoice, "credit note {$creditNote->credit_note_number}");

                // Credit is held in the document currency; moving it to an
                // invoice in another currency would need an FX settlement.
                if ($currency && $invoice->currency_code && strtoupper((string) $currency) !== strtoupper((string) $invoice->currency_code)) {
                    throw new \InvalidArgumentException(
                        "Invoice {$invoice->invoice_number} is in {$invoice->currency_code}; the credit note's credit is in {$currency}."
                    );
                }

                $this->assertOpenReceivable($invoice);

                if (in_array($invoice->id, $seenInvoices, true)) {
                    throw new \InvalidArgumentException(
                        "Invoice {$invoice->invoice_number} appears more than once in the application."
                    );
                }
                $seenInvoices[] = $invoice->id;

                $open = round(max(0.0, (float) $invoice->total - (float) $invoice->paid_amount
                    - (float) $invoice->credited_amount - (float) $invoice->written_off_amount), 2);

                if ($amount > $open + 0.005) {
                    throw new \InvalidArgumentException(
                        "The application ({$amount}) exceeds the outstanding balance of invoice {$invoice->invoice_number} ({$open})."
                    );
                }

                $this->invoiceService->transferCustomerCredit(
                    $origin,
                    $invoice,
                    $amount,
                    "Credit note {$creditNote->credit_note_number} applied to {$invoice->invoice_number}",
                );

                CustomerCreditNoteApplication::create([
                    'credit_note_id' => $creditNote->id,
                    'invoice_id' => $invoice->id,
                    'applied_amount' => $amount,
                    'currency_code' => $currency ?: null,
                    'created_by' => \Modules\OCRBookkeeping\Helpers\TenantUserHelper::resolveTenantUserId(),
                ]);
            }

            return $creditNote->fresh(['applications.invoice']);
        });
    }

    /**
     * Issue #495: undo every live application of a credit note — move the
     * credit back to the note's own invoice and mark the application reversed
     * (audit preserved). Called when the credit note is voided, before its own
     * credit is released, so no invoice keeps credit from a voided note.
     */
    public function reverseCreditNoteApplications(CustomerCreditNote $creditNote): void
    {
        $origin = $creditNote->invoice_id ? Invoice::find($creditNote->invoice_id) : null;

        foreach ($creditNote->applications()->whereNull('reversed_at')->with('invoice')->get() as $application) {
            if ($origin && $application->invoice) {
                $this->invoiceService->transferCustomerCredit(
                    $application->invoice,
                    $origin,
                    (float) $application->applied_amount,
                    "Credit note {$creditNote->credit_note_number} application reversed",
                );
            }

            $application->update(['reversed_at' => now()]);
        }
    }

    /**
     * #495: a credit source belongs to one legal entity's books; it can only
     * settle receivables of that entity. Legacy rows without an entity are
     * not blocked.
     */
    private function assertSameEntity(mixed $sourceEntityId, Invoice $invoice, string $sourceLabel): void
    {
        if ($sourceEntityId !== null && $invoice->legal_entity_id !== null
            && (int) $sourceEntityId !== (int) $invoice->legal_entity_id) {
            throw new \InvalidArgumentException(
                "Invoice {$invoice->invoice_number} belongs to another legal entity than {$sourceLabel}."
            );
        }
    }

    private function assertOpenReceivable(Invoice $invoice): void
    {
        if (! in_array((string) $invoice->lifecycle_status, ['posted', 'credited'], true)
            || in_array((string) $invoice->status, ['draft', 'cancelled'], true)) {
            throw new \InvalidArgumentException(
                "Invoice {$invoice->invoice_number} is not an open posted receivable "
                ."(lifecycle '{$invoice->lifecycle_status}', status '{$invoice->status}')."
            );
        }
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

        // ACC-005B: a correction/reversal must be visible on the settlement too.
        app(SettlementRecognitionService::class)->recognisePayment($payment->fresh());
    }
}
