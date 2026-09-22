<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single canonical computation for every Statement of Account surface.
 *
 * Statement bugs #191-#203 all came from parallel, diverging statement math:
 * `statement()` summed live invoice balances, `generate()` summed the
 * ar_transactions view (which already nets payments into the invoice debit and
 * emits payments again — double counting), and the PDF data source re-queried
 * live invoices for aging. This service replaces all of those paths with one
 * ledger built from source documents.
 *
 * Ledger model:
 *   - posted invoice     => debit  (full document total)
 *   - posted payment     => credit (customer receipt)
 *   - posted credit note => credit
 *   - opening balance row carries the pre-period net (range statements only)
 *   - running balance = opening + cumulative(debit - credit)
 *   - closing == true outstanding at the statement end date, so an as-of
 *     statement and a range statement ending on the same date agree (#193).
 *
 * Inclusion rules:
 *   - include_paid = true  : every posted document in the period (full ledger).
 *   - include_paid = false : invoices still outstanding as of end, plus the
 *     payments/credit notes that belong to them (or on-account receipts), so a
 *     statement never shows an orphan credit (#199). Fully settled invoices and
 *     their settlements drop out together.
 *
 * Aging is computed here, once, from the same document snapshot and stored in
 * the payload, so the PDF data source reads a frozen number instead of
 * re-querying live data (#197/#198) and never crashes on a missing due date
 * (#203).
 */
class CustomerStatementService
{
    private const EPSILON = 0.005;

    /**
     * Canonical payload for a date range (the emailed/generated statement).
     */
    public function buildRangePayload(
        Customer $customer,
        Carbon $start,
        Carbon $end,
        bool $includePaid = false,
        ?string $periodType = 'custom',
    ): array {
        return $this->build($customer, $start, $end, $includePaid, $periodType);
    }

    /**
     * Canonical payload for an "as of" outstanding snapshot (the quick-view
     * statement). Never includes an opening balance row.
     */
    public function buildAsOfPayload(Customer $customer, Carbon $asOf): array
    {
        return $this->build($customer, null, $asOf, false, 'as_of');
    }

    /**
     * Per-date snapshot used by the year-over-year comparison endpoints.
     */
    public function comparisonSnapshot(Customer $customer, Carbon $asOf): array
    {
        $payload = $this->buildAsOfPayload($customer, $asOf);
        $invoiceRows = collect($payload['transactions'])->where('type', 'Invoice');

        return [
            'as_of_date' => $asOf->format('Y-m-d'),
            'total_outstanding' => $payload['closing_balance'],
            'total_invoice_value' => round($invoiceRows->sum('inv_amount'), 2),
            'invoice_count' => $invoiceRows->count(),
            'aging' => $payload['aging'],
            'transactions' => $payload['transactions'],
        ];
    }

    /**
     * Company / customer / bank / currency context shared by every statement
     * surface.
     */
    public function context(Customer $customer): array
    {
        $setting = Setting::first();
        $bank = DB::table('bank_accounts')->first();

        $billingAddress = $customer->addresses()
            ->where('type', 'billing')
            ->where('is_default', true)
            ->first();

        $address = $billingAddress
            ? collect([
                $billingAddress->street,
                $billingAddress->street2,
                $billingAddress->city,
                $billingAddress->state,
                $billingAddress->zip,
                $billingAddress->country,
            ])->filter()->implode(', ')
            : $customer->billing_address;

        $attentionTo = $customer->customerContacts()
            ->where('is_primary', true)
            ->first()?->name ?? $customer->name;

        return [
            'company' => [
                'name' => $setting->company_name ?? '',
                'address' => $setting->company_address ?? '',
                'email' => $setting->company_email ?? '',
                'phone' => $setting->company_phone ?? '',
                'registration_number' => $setting->registration_number ?? '',
            ],
            'customer' => [
                'id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->name,
                'email' => $customer->email,
                'attention_to' => $attentionTo,
                'address' => $address,
                'billing_address' => $address,
                'payment_terms' => $customer->payment_terms ? $customer->payment_terms.' Days from Invoice Date' : '',
            ],
            'currency' => $setting->currency ?? 'MYR',
            'bank' => [
                'bank_name' => $bank->bank_name ?? '',
                'account_name' => $bank->account_name ?? '',
                'account_number' => $bank->account_number ?? '',
                'swift_code' => $bank->swift_code ?? '',
                'bank_code' => $bank->bank_code ?? '',
            ],
        ];
    }

    /**
     * Aging buckets for an invoice snapshot. Null due dates bucket as current
     * (#203). Amounts are the per-invoice outstanding computed at build time.
     *
     * @param  array<int|string, float>  $outstandingByInvoice
     */
    public function agingFor(Collection $invoices, Carbon $asOf, array $outstandingByInvoice): array
    {
        $buckets = [
            'current' => 0.0,
            'days_1_30' => 0.0,
            'days_31_60' => 0.0,
            'days_61_90' => 0.0,
            'days_90_plus' => 0.0,
            'total' => 0.0,
        ];

        foreach ($invoices as $invoice) {
            $amount = round((float) ($outstandingByInvoice[$invoice->id] ?? 0), 2);
            if ($amount < self::EPSILON) {
                continue;
            }

            $bucket = 'current';
            if ($invoice->due_date) {
                $dueDate = Carbon::parse($invoice->due_date);
                if ($dueDate->lt($asOf)) {
                    $overdueDays = (int) $dueDate->diffInDays($asOf);
                    if ($overdueDays <= 30) {
                        $bucket = 'days_1_30';
                    } elseif ($overdueDays <= 60) {
                        $bucket = 'days_31_60';
                    } elseif ($overdueDays <= 90) {
                        $bucket = 'days_61_90';
                    } else {
                        $bucket = 'days_90_plus';
                    }
                }
            }

            $buckets[$bucket] = round($buckets[$bucket] + $amount, 2);
            $buckets['total'] = round($buckets['total'] + $amount, 2);
        }

        return $buckets;
    }

    private function build(
        Customer $customer,
        ?Carbon $start,
        Carbon $end,
        bool $includePaid,
        ?string $periodType,
    ): array {
        $context = $this->context($customer);

        // Eager-load lines: the transaction description falls back to the first
        // line description, which previously triggered a query per invoice (#201).
        $invoices = Invoice::query()
            ->with(['lines:id,invoice_id,description'])
            ->where('customer_id', $customer->id)
            ->whereIn('lifecycle_status', ['posted', 'credited'])
            ->where('invoice_date', '<=', $end->toDateString())
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get();

        // Issue #205/#207: only actually-applied, active payments count as
        // credits. 'unapplied' (cancelled via unapplyPayment) restores the
        // invoice balance, and 'recorded' is still an editable draft with no
        // ledger impact — neither may reduce what the customer owes.
        $payments = Payment::query()
            ->where('customer_id', $customer->id)
            ->where('payment_status', 'applied')
            ->where('payment_date', '<=', $end->toDateString())
            ->orderBy('payment_date')
            ->orderBy('id')
            ->get();

        $creditNotes = CustomerCreditNote::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'posted')
            ->where('credit_note_date', '<=', $end->toDateString())
            ->orderBy('credit_note_date')
            ->orderBy('id')
            ->get();

        $invoiceIds = $invoices->pluck('id')->flip();

        // Applied-to-date per invoice (payments and credit notes up to end).
        $appliedByInvoice = [];
        foreach ($payments as $payment) {
            if ($payment->invoice_id && isset($invoiceIds[$payment->invoice_id])) {
                $appliedByInvoice[$payment->invoice_id] = ($appliedByInvoice[$payment->invoice_id] ?? 0)
                    + (float) $payment->amount;
            }
        }
        foreach ($creditNotes as $creditNote) {
            if ($creditNote->invoice_id && isset($invoiceIds[$creditNote->invoice_id])) {
                $appliedByInvoice[$creditNote->invoice_id] = ($appliedByInvoice[$creditNote->invoice_id] ?? 0)
                    + (float) $creditNote->total_amount;
            }
        }

        $outstandingByInvoice = [];
        foreach ($invoices as $invoice) {
            $outstandingByInvoice[$invoice->id] = max(
                0.0,
                round((float) $invoice->total - ($appliedByInvoice[$invoice->id] ?? 0.0), 2)
            );
        }

        // Issue #206: invoices dated before the range start are folded whole
        // into opening_balance and their rows are never shown — a settlement of
        // one of them must still reduce the running balance, so they must be
        // distinguishable from invoices hidden because they are fully settled.
        $prePeriodInvoiceIds = [];
        if ($start !== null) {
            foreach ($invoices as $invoice) {
                if ($this->before($invoice->invoice_date, $start)) {
                    $prePeriodInvoiceIds[$invoice->id] = true;
                }
            }
        }

        // Pre-period net (all countable documents before the range start).
        $openingBalance = 0.0;
        if ($start !== null) {
            foreach ($invoices as $invoice) {
                if ($this->before($invoice->invoice_date, $start)) {
                    $openingBalance += (float) $invoice->total;
                }
            }
            foreach ($payments as $payment) {
                if ($this->countablePayment($payment, $invoiceIds) && $this->before($payment->payment_date, $start)) {
                    $openingBalance -= (float) $payment->amount;
                }
            }
            foreach ($creditNotes as $creditNote) {
                if ($this->countableCreditNote($creditNote, $invoiceIds) && $this->before($creditNote->credit_note_date, $start)) {
                    $openingBalance -= (float) $creditNote->total_amount;
                }
            }
        }
        $openingBalance = round($openingBalance, 2);

        $rows = [];

        if ($start !== null && abs($openingBalance) >= self::EPSILON) {
            $rows[] = [
                'sort_date' => $start->toDateString(),
                'sort_id' => 0,
                'is_opening' => true,
                'date' => $start->toDateString(),
                'type' => 'Opening Balance',
                'document_number' => '',
                'description' => 'Balance brought forward',
                'reference' => '',
                'due_date' => '',
                'inv_amount' => 0.0,
                'debit' => $openingBalance > 0 ? $openingBalance : 0.0,
                'credit' => $openingBalance < 0 ? -$openingBalance : 0.0,
                'outstanding' => null,
                'status' => 'open',
            ];
        }

        foreach ($invoices as $invoice) {
            if ($start !== null && $this->before($invoice->invoice_date, $start)) {
                continue;
            }
            if (! $includePaid && $outstandingByInvoice[$invoice->id] < self::EPSILON) {
                continue;
            }

            $reference = collect([$invoice->po_number, $invoice->customer_reference])
                ->filter()
                ->implode(', ');

            $rows[] = [
                'sort_date' => $invoice->invoice_date->toDateString(),
                'sort_id' => $invoice->id,
                'date' => $invoice->invoice_date->format('Y-m-d'),
                'type' => 'Invoice',
                'document_number' => $invoice->invoice_number,
                'description' => $reference ?: ($invoice->lines->pluck('description')->filter()->first() ?? ''),
                'reference' => $reference,
                'due_date' => $invoice->due_date ? $invoice->due_date->format('Y-m-d') : '',
                'inv_amount' => round((float) $invoice->total, 2),
                'debit' => round((float) $invoice->total, 2),
                'credit' => 0.0,
                'outstanding' => $outstandingByInvoice[$invoice->id],
                'status' => $invoice->status,
            ];
        }

        foreach ($payments as $payment) {
            if ($start !== null && $this->before($payment->payment_date, $start)) {
                continue;
            }
            if (! $this->countablePayment($payment, $invoiceIds)) {
                continue;
            }
            // Without include_paid, a receipt whose invoice is already settled
            // is skipped together with that invoice — no orphan credit (#199).
            // Issue #206: but never skip when the invoice was excluded because
            // it predates the range (its settlement still applies here).
            if (! $includePaid && $payment->invoice_id !== null
                && ! isset($prePeriodInvoiceIds[$payment->invoice_id])
                && ($outstandingByInvoice[$payment->invoice_id] ?? 0.0) < self::EPSILON) {
                continue;
            }

            $rows[] = [
                'sort_date' => $payment->payment_date->toDateString(),
                'sort_id' => $payment->id,
                'date' => $payment->payment_date->format('Y-m-d'),
                'type' => 'Payment',
                'document_number' => $payment->payment_number,
                'description' => $payment->reference ?: 'Payment received',
                'reference' => $payment->reference ?? '',
                'due_date' => '',
                'inv_amount' => 0.0,
                'debit' => 0.0,
                'credit' => round((float) $payment->amount, 2),
                'outstanding' => null,
                'status' => $payment->payment_status,
            ];
        }

        foreach ($creditNotes as $creditNote) {
            if ($start !== null && $this->before($creditNote->credit_note_date, $start)) {
                continue;
            }
            if (! $this->countableCreditNote($creditNote, $invoiceIds)) {
                continue;
            }
            if (! $includePaid && $creditNote->invoice_id !== null
                && ! isset($prePeriodInvoiceIds[$creditNote->invoice_id])
                && ($outstandingByInvoice[$creditNote->invoice_id] ?? 0.0) < self::EPSILON) {
                continue;
            }

            $rows[] = [
                'sort_date' => $creditNote->credit_note_date->toDateString(),
                'sort_id' => $creditNote->id,
                'date' => $creditNote->credit_note_date->format('Y-m-d'),
                'type' => 'Credit Note',
                'document_number' => $creditNote->credit_note_number,
                'description' => $creditNote->reason ?: 'Credit note applied',
                'reference' => '',
                'due_date' => '',
                'inv_amount' => 0.0,
                'debit' => 0.0,
                'credit' => round((float) $creditNote->total_amount, 2),
                'outstanding' => null,
                'status' => $creditNote->status,
            ];
        }

        usort($rows, function (array $a, array $b): int {
            return [$a['sort_date'], $a['sort_id']] <=> [$b['sort_date'], $b['sort_id']];
        });

        // Issue #204: the running balance is seeded with the opening balance,
        // and the synthetic "Opening Balance" row's debit/credit encode that
        // same amount — so that row must NOT be accumulated again, or every
        // balance (and the closing balance) is inflated by the opening balance.
        $runningBalance = $openingBalance;
        $totalDebits = 0.0;
        $totalCredits = 0.0;
        $transactions = [];

        foreach ($rows as $row) {
            if (! ($row['is_opening'] ?? false)) {
                $runningBalance = round($runningBalance + $row['debit'] - $row['credit'], 2);
                $totalDebits += $row['debit'];
                $totalCredits += $row['credit'];
            }

            unset($row['sort_date'], $row['sort_id'], $row['is_opening']);
            $row['balance'] = $runningBalance;
            $transactions[] = $row;
        }

        $closingBalance = round($runningBalance, 2);

        return [
            'company' => $context['company'],
            'customer' => $context['customer'],
            'period' => [
                'start_date' => $start?->toDateString(),
                'end_date' => $end->toDateString(),
                'type' => $periodType,
            ],
            'as_of_date' => $end->toDateString(),
            'currency' => $context['currency'],
            'opening_balance' => $openingBalance,
            'closing_balance' => $closingBalance,
            'total_amount_due' => $closingBalance,
            'total_debits' => round($totalDebits, 2),
            'total_credits' => round($totalCredits, 2),
            'transactions' => $transactions,
            'aging' => $this->agingFor($invoices, $end, $outstandingByInvoice),
            'bank' => $context['bank'],
            'generated_at' => now()->toISOString(),
            'include_paid' => $includePaid,
        ];
    }

    private function countablePayment(Payment $payment, Collection $invoiceIds): bool
    {
        // On-account receipts (no invoice link) always count; receipts linked to
        // an invoice outside the countable universe (voided/superseded) are
        // dropped so they cannot surface as unexplained credits.
        return $payment->invoice_id === null || isset($invoiceIds[$payment->invoice_id]);
    }

    private function countableCreditNote(CustomerCreditNote $creditNote, Collection $invoiceIds): bool
    {
        return $creditNote->invoice_id !== null && isset($invoiceIds[$creditNote->invoice_id]);
    }

    private function before(Carbon|\DateTimeInterface|string $date, Carbon $start): bool
    {
        return Carbon::parse($date)->lt($start);
    }
}
