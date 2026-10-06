<?php

namespace App\Services;

use App\Exceptions\PartialPaymentNotAllowedException;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\TenantUserHelper;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Issue #489 (PAY-UX3): customer-first receipt — "ABC Sdn Bhd paid RM 12,400".
 *
 * Records ONE customer-level payment, posts it through the canonical
 * PaymentPostingService (PostingEngine: DR bank / CR AR) and allocates it
 * across the customer's invoices through the #155 PaymentAllocationService,
 * all in one transaction: an invalid allocation rolls back the payment and
 * its journal entry. Whatever is not allocated stays on the payment as
 * unapplied credit (#155/#488). No ledger row is written here directly.
 */
class CustomerReceiptService
{
    public function __construct(
        private readonly PaymentPostingService $postingService,
        private readonly PaymentAllocationService $allocationService,
        private readonly SalesSettings $salesSettings,
    ) {}

    /**
     * @param  array{customer_id:int, amount:float|string, payment_date?:string|null, method:string, reference?:string|null, notes?:string|null, bank_account_id?:int|null}  $data
     * @param  array<int, array{invoice_id:int, amount:float|string}>  $applications
     *
     * @throws \InvalidArgumentException when the receipt or an allocation is invalid
     * @throws \RuntimeException when the posting engine rejects the receipt
     */
    public function receive(array $data, array $applications): Payment
    {
        $customer = Customer::findOrFail($data['customer_id']);
        $amount = round((float) $data['amount'], 2);

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Payment amount must be greater than zero.');
        }

        $applied = round(array_sum(array_map(fn (array $a) => (float) $a['amount'], $applications)), 2);
        if ($applied > $amount + 0.001) {
            throw new \InvalidArgumentException(
                "The allocation total ({$applied}) exceeds the received amount ({$amount})."
            );
        }

        $baseCurrency = strtoupper((string) DocumentCurrencyResolver::baseFor(null, $customer->id));
        $allowPartial = $this->salesSettings->allowPartialPayments();

        foreach ($applications as $application) {
            $invoice = Invoice::findOrFail($application['invoice_id']);

            // A customer-level receipt has no single invoice rate, so realized
            // FX cannot be computed per invoice — foreign-currency invoices are
            // paid one at a time from the invoice (with the #490 FX preview).
            $invoiceCurrency = strtoupper((string) ($invoice->currency_code ?: $baseCurrency));
            if ($invoiceCurrency !== $baseCurrency) {
                throw new \InvalidArgumentException(
                    "Invoice {$invoice->invoice_number} is in {$invoiceCurrency}; record foreign-currency payments from the invoice."
                );
            }

            // #539: with partial payments disabled, a receipt applied to an
            // invoice must settle its whole outstanding balance.
            if (! $allowPartial
                && bccomp(number_format((float) $application['amount'], 2, '.', ''), number_format((float) $invoice->balance, 2, '.', ''), 2) < 0) {
                throw new PartialPaymentNotAllowedException(
                    "Partial payments are disabled by the sales setting sales.invoice.allow_partial_payments. Apply the full outstanding balance of invoice {$invoice->invoice_number} (".number_format((float) $invoice->balance, 2, '.', '').').'
                );
            }
        }

        return DB::transaction(function () use ($data, $customer, $amount, $baseCurrency, $applications) {
            $payment = Payment::create([
                'payment_number' => Payment::generatePaymentNumber(),
                'customer_id' => $customer->id,
                'invoice_id' => null,
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'amount' => $amount,
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'currency' => $baseCurrency,
                'base_currency_code' => $baseCurrency,
                'exchange_rate' => 1.0,
                'received_by' => TenantUserHelper::requireTenantUserId(Auth::id()),
                'source' => 'manual',
            ]);

            $payment = $this->postingService->post($payment);

            if ($applications !== []) {
                $this->allocationService->allocate($payment, $applications);
            }

            return $payment->fresh(['customer', 'journalEntry', 'applications.invoice']);
        });
    }
}
