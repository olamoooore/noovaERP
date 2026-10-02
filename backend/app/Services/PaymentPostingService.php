<?php

namespace App\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\BankAccount;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

/**
 * Posts draft customer payments to the general ledger.
 *
 * Posting is the single transition that moves a payment from
 * draft (no ledger impact) to posted (locked, journal_entry_id set).
 * Invoice allocation happens at creation time — this service only
 * creates the journal entry and flips the status.
 *
 * Shared correction contract with PaymentCorrectionService (issue #45):
 *   This service posts BOTH invoice/bill-linked and STANDALONE payments
 *   (a standalone payment leaves an AR customer credit that nets against
 *   future invoices). Because a standalone payment is a supported,
 *   intentional posting shape, it MUST also be correctable
 *   (reverse/refund/unapply) via PaymentCorrectionService. Do not post a
 *   shape here that PaymentCorrectionService cannot later reverse, and do
 *   not reject a standalone payment in correction. See PaymentCorrectionService
 *   class docblock for the full contract and Issue45StandalonePaymentCorrectionTest.
 */
class PaymentPostingService
{
    public function __construct(
        private PostingEngineInterface $postingEngine,
    ) {}

    /**
     * Post a single payment.
     *
     * Idempotent:
     *  - already carries a journal entry → returned untouched (status repaired
     *    to the canonical posted status `applied` if needed)
     *
     * Canonical statuses (#73): a posted payment is `applied`; the legacy
     * `posted` label no longer exists in the enum.
     *
     * @throws \RuntimeException when the posting engine rejects the request
     */
    public function post(Payment $payment, ?int $userId = null): Payment
    {
        if ($payment->journal_entry_id !== null) {
            return $this->repairPostedStatus($payment);
        }

        $resolvedUserId = $userId ?? TenantUserHelper::resolveTenantUserId() ?? $payment->received_by;

        return DB::transaction(function () use ($payment, $resolvedUserId) {
            // Re-fetch inside the transaction to avoid double-posting races.
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->journal_entry_id !== null) {
                return $this->repairPostedStatus($payment);
            }

            $bankGlAccountId = null;
            if (! empty($payment->bank_account_id)) {
                $bankGlAccountId = BankAccount::find($payment->bank_account_id)?->gl_account_id;
            }

            // ── #44: policy-driven payment method → GL account mapping ──
            $method = $payment->method ?? 'cash';

            // 'other' is accepted by PaymentController::store() and produced by
            // PaymentImportService::resolveMethod() for unclassified import
            // values — it MUST be postable here too, otherwise imported
            // payments are permanently stuck in `recorded` with no GL entry
            // (production report 2026-09-24). Unclassified receipts post to
            // the default bank account, matching the platform's dominant
            // settlement path.
            // #486: the tenant method master drives allowance + mapping; the
            // hardcoded map stays as the fallback for unseeded tenants.
            $master = PaymentMethod::query()->active()->where('code', $method)->first();

            $allowedMethods = ['bank_transfer', 'card', 'check', 'cash', 'gateway', 'other'];
            if ($master === null && ! in_array($method, $allowedMethods, true)) {
                throw new \RuntimeException(
                    "Unsupported payment method: '{$method}'. ".
                    'Allowed: '.implode(', ', array_unique([...$allowedMethods, ...PaymentMethod::query()->active()->pluck('code')->all()]))
                );
            }

            $cashMappingKey = $master?->mapping_key ?? match ($method) {
                'bank_transfer' => 'bank',
                'card' => 'card_clearing',
                'check' => 'cheque_clearing',
                'cash' => 'cash',
                'gateway' => 'gateway_receivable',
                'other' => 'bank',
            };

            // Master may pin a default deposit journal for the method — used
            // when the payment itself carries no explicit bank account.
            if ($bankGlAccountId === null && $master?->default_bank_account_id) {
                $bankGlAccountId = BankAccount::find($master->default_bank_account_id)?->gl_account_id;
            }

            // ── MRD pricelist-multicurrency FR-7.2/7.3 / AC-4 ────────────
            // Realized FX: AR clears at the INVOICE snapshot rate, the bank
            // receives at the PAYMENT-date rate, and the difference posts to
            // the realized gain/loss accounts. Base-currency payments keep
            // both rates at 1.0, so this collapses exactly to the historical
            // "same amount on both legs" posting.
            $invoice = $payment->invoice;

            $docCurrency = strtoupper((string) ($payment->currency
                ?? $invoice?->currency_code
                ?? \App\Services\DocumentCurrencyResolver::resolve()));
            $baseCurrency = strtoupper((string) ($payment->base_currency_code
                ?? $invoice?->base_currency_code
                ?? \App\Services\DocumentCurrencyResolver::baseFor(
                    $invoice?->salesOrder?->legal_entity_id,
                    $payment->customer_id
                )));
            $foreign = $docCurrency !== $baseCurrency;

            $payRate = $payment->exchange_rate !== null ? (float) $payment->exchange_rate : null;

            if ($payRate === null || $payRate <= 0) {
                if (! $foreign) {
                    $payRate = 1.0;
                } else {
                    $snapshot = \App\Services\DocumentCurrencyResolver::snapshot(
                        $docCurrency,
                        $payment->customer_id,
                        $invoice?->salesOrder?->legal_entity_id,
                        $payment->payment_date instanceof \DateTimeInterface
                            ? $payment->payment_date->format('Y-m-d')
                            : (string) $payment->payment_date
                    );
                    $payRate = (float) $snapshot['exchange_rate'];
                    $baseCurrency = strtoupper((string) $snapshot['base_currency_code']);
                }

                // Persist the resolved settlement snapshot so a re-post is
                // deterministic and the audit can see the rate used (FR-3.9).
                $payment->update([
                    'currency' => $docCurrency,
                    'exchange_rate' => $payRate,
                    'base_currency_code' => $baseCurrency,
                ]);
            }

            // §9.5: fx = round(applied × (payment_rate − invoice_rate), 2).
            // A STANDALONE payment (no invoice to clear) has no second rate —
            // both legs carry the settlement rate, so the FX leg is zero by
            // construction and the posting matches the historical shape.
            if ($invoice) {
                $invoiceRate = $invoice->exchange_rate !== null ? (float) $invoice->exchange_rate : 1.0;
            } else {
                $invoiceRate = $payRate;
            }

            // #478: a cash discount settles part of the invoice without cash —
            // debit Sales discount and credit AR by amount + discount. Realized
            // FX applies only to the cash leg, so its base stays
            // bankBase − amount×invoiceRate; the discount is converted at the
            // invoice snapshot rate (it reduces the receivable at that rate).
            $discount = round((float) ($payment->discount_amount ?? 0), 2);
            $bankBase = round((float) $payment->amount * $payRate, 2);
            $arCashBase = round((float) $payment->amount * $invoiceRate, 2);
            $discountBase = round($discount * $invoiceRate, 2);
            $arBase = round($arCashBase + $discountBase, 2);
            $fx = round($bankBase - $arCashBase, 2);

            $lines = [
                // DR Cash/Bank (at settlement rate)
                new PostingRequestLine(
                    accountMappingKey: $cashMappingKey,
                    accountId: $bankGlAccountId,
                    debit: $bankBase,
                    credit: 0,
                    description: "Payment {$payment->payment_number}".($foreign ? " ({$payment->amount} {$docCurrency} @ {$payRate})" : ''),
                    currencyCode: $foreign ? $baseCurrency : null,
                    originalAmount: $foreign ? (float) $payment->amount : null,
                    exchangeRate: $foreign ? $payRate : null,
                ),
            ];

            if ($discount > 0) {
                // DR Sales discount allowed (the non-cash part of the settlement)
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'sales_discount',
                    accountId: null,
                    debit: $discountBase,
                    credit: 0,
                    description: 'Sales discount - '.$payment->payment_number,
                    currencyCode: $foreign ? $baseCurrency : null,
                    originalAmount: $foreign ? $discount : null,
                    exchangeRate: $foreign ? $invoiceRate : null,
                );
            }

            // CR Accounts Receivable (at the invoice snapshot rate) — for
            // standalone payments this leaves a customer credit that nets
            // against future invoices. The AR cleared is amount + discount.
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'accounts_receivable',
                accountId: null,
                debit: 0,
                credit: $arBase,
                description: 'Payment received - '.$payment->payment_number,
                currencyCode: $foreign ? $baseCurrency : null,
                originalAmount: $foreign ? round((float) $payment->amount + $discount, 2) : null,
                exchangeRate: $foreign ? $invoiceRate : null,
            );

            if ($fx > 0) {
                // Received MORE in base than AR cleared → realized FX gain.
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'realized_fx_gain',
                    accountId: null,
                    debit: 0,
                    credit: $fx,
                    description: 'Realized FX gain - payment '.$payment->payment_number,
                );
            } elseif ($fx < 0) {
                // Received LESS in base → realized FX loss.
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'realized_fx_loss',
                    accountId: null,
                    debit: round(-$fx, 2),
                    credit: 0,
                    description: 'Realized FX loss - payment '.$payment->payment_number,
                );
            }

            $result = $this->postingEngine->post(new PostingRequest(
                documentType: 'payment',
                documentId: $payment->id,
                postingDate: $payment->payment_date instanceof \DateTimeInterface
                    ? $payment->payment_date->format('Y-m-d')
                    : (string) $payment->payment_date,
                description: 'Payment #'.$payment->payment_number
                    .($payment->customer ? ' - '.$payment->customer->name : ''),
                lines: $lines,
                reference: $payment->payment_number,
                currencyCode: $foreign ? $docCurrency : null,
                exchangeRate: $foreign ? $payRate : null,
                userId: TenantUserHelper::requireTenantUserId($resolvedUserId),
            ));

            if (! $result->success) {
                throw new \RuntimeException($result->error);
            }

            $payment->update([
                'journal_entry_id' => $result->journalEntryId,
                'payment_status' => 'applied',
            ]);

            // #477: settlement moves WITH the journal entry — posting is the one
            // place the invoice's paid_amount/balance/status/AR are updated. A
            // draft never touched the invoice; this is where it finally does.
            app(\App\Services\InvoiceService::class)->settlePostedPayment($payment->fresh());

            Log::info('Payment posted to ledger', [
                'payment_id' => $payment->id,
                'payment_number' => $payment->payment_number,
                'journal_entry_id' => $result->journalEntryId,
                'currency' => $docCurrency,
                'base_currency' => $baseCurrency,
            ]);

            // Observability (FR-13): dedicated fx.posted event.
            if ($foreign) {
                Log::info('fx.posted', [
                    'payment_id' => $payment->id,
                    'invoice_id' => $invoice?->id,
                    'currency' => $docCurrency,
                    'base_currency' => $baseCurrency,
                    'payment_rate' => $payRate,
                    'invoice_rate' => $invoiceRate,
                    'realized_fx' => $fx,
                ]);
            }

            return $payment->fresh(['customer', 'invoice', 'journalEntry']);
        });
    }

    /**
     * Post many payments. Returns per-id outcomes so the UI can show
     * exactly which rows were posted and which were skipped (and why).
     *
     * @param  int[]  $ids
     * @return array{posted: int[], skipped: array<int, array{id: int, reason: string}>}
     */
    public function postMany(array $ids): array
    {
        $posted = [];
        $skipped = [];

        foreach ($ids as $id) {
            $payment = Payment::find($id);
            if (! $payment) {
                $skipped[] = ['id' => $id, 'reason' => 'Payment not found'];

                continue;
            }

            try {
                $this->post($payment);
                $posted[] = $id;
            } catch (\Throwable $e) {
                Log::warning('Bulk payment posting failed', [
                    'payment_id' => $id,
                    'error' => $e->getMessage(),
                ]);
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        return compact('posted', 'skipped');
    }

    /**
     * Canonical posted status is `applied` (#73). Legacy rows may still carry
     * `recorded` (or the removed `posted` label) alongside a journal entry —
     * normalise the label without touching the ledger link or re-posting.
     */
    private function repairPostedStatus(Payment $payment): Payment
    {
        if ($payment->payment_status !== 'applied') {
            $payment->update(['payment_status' => 'applied']);
        }

        return $payment->fresh(['customer', 'invoice', 'journalEntry']);
    }
}
