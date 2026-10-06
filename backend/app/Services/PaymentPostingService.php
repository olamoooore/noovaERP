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
     * #490 (PAY-UX4): the realized-FX settlement computation for a payment —
     * shared by post() and the read-only GET /payments/fx-preview endpoint, so
     * what the operator previews is exactly what posts. Pure: never writes.
     *
     * Realized FX: AR clears at the INVOICE snapshot rate, the bank receives
     * at the PAYMENT rate (the payment's stored/edited exchange_rate, else the
     * payment-date snapshot), and the difference is the realized gain/loss.
     * Base-currency payments keep both rates at 1.0.
     *
     * @return array{currency:string, base_currency_code:string, foreign:bool, exchange_rate:float, rate_source:string, rate_resolved:bool, invoice_rate:float, amount:float, discount_amount:float, base_amount:float, ar_cash_base_amount:float, discount_base_amount:float, ar_base_amount:float, fx_amount:float, fx_type:string}
     *
     * @throws \App\Exceptions\PricingException when a foreign payment has no rate
     */
    public function settlementPreview(Payment $payment): array
    {
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
        $rateSource = 'stored';
        $rateResolved = false;

        if ($payRate === null || $payRate <= 0) {
            $rateResolved = true;
            if (! $foreign) {
                $payRate = 1.0;
                $rateSource = 'same_currency';
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
                $rateSource = (string) $snapshot['rate_source'];
            }
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
        $amount = round((float) $payment->amount, 2);
        $discount = round((float) ($payment->discount_amount ?? 0), 2);
        $bankBase = round((float) $payment->amount * $payRate, 2);
        $arCashBase = round((float) $payment->amount * $invoiceRate, 2);
        $discountBase = round($discount * $invoiceRate, 2);
        $arBase = round($arCashBase + $discountBase, 2);
        $fx = round($bankBase - $arCashBase, 2);

        return [
            'currency' => $docCurrency,
            'base_currency_code' => $baseCurrency,
            'foreign' => $foreign,
            'exchange_rate' => $payRate,
            'rate_source' => $rateSource,
            'rate_resolved' => $rateResolved,
            'invoice_rate' => $invoiceRate,
            'amount' => $amount,
            'discount_amount' => $discount,
            'base_amount' => $bankBase,
            'ar_cash_base_amount' => $arCashBase,
            'discount_base_amount' => $discountBase,
            'ar_base_amount' => $arBase,
            'fx_amount' => $fx,
            'fx_type' => $fx > 0 ? 'gain' : ($fx < 0 ? 'loss' : 'none'),
        ];
    }

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
            // #490: the settlement maths lives in settlementPreview() so the
            // pre-posting preview shown to the operator is the SAME
            // computation that posts here (preview == posted).
            $invoice = $payment->invoice;
            $settlement = $this->settlementPreview($payment);

            $docCurrency = $settlement['currency'];
            $baseCurrency = $settlement['base_currency_code'];
            $foreign = $settlement['foreign'];
            $payRate = $settlement['exchange_rate'];
            $invoiceRate = $settlement['invoice_rate'];

            if ($settlement['rate_resolved']) {
                // Persist the resolved settlement snapshot so a re-post is
                // deterministic and the audit can see the rate used (FR-3.9).
                $payment->update([
                    'currency' => $docCurrency,
                    'exchange_rate' => $payRate,
                    'base_currency_code' => $baseCurrency,
                ]);
            }

            $discount = $settlement['discount_amount'];
            $bankBase = $settlement['base_amount'];
            $discountBase = $settlement['discount_base_amount'];
            $arBase = $settlement['ar_base_amount'];
            $fx = $settlement['fx_amount'];

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

            // Issue #875: bind the posting to the payment's legal entity. Standalone
            // payments resolve through the invoice (when present), the customer,
            // the request entity context, or the tenant's only entity.
            $entityResolver = app(\App\Services\LegalEntityContextResolver::class);
            $legalEntityId = $entityResolver->resolveEntity(
                explicit: $payment->legal_entity_id ? (int) $payment->legal_entity_id : null,
                invoiceId: $payment->invoice_id ? (int) $payment->invoice_id : null,
                customerId: $payment->customer_id ? (int) $payment->customer_id : null,
                required: true,
            );
            $entityBookId = $payment->entity_book_id
                ? (int) $payment->entity_book_id
                : $entityResolver->resolveBookId($legalEntityId);

            if ($legalEntityId && (int) $payment->legal_entity_id !== $legalEntityId) {
                $payment->forceFill([
                    'legal_entity_id' => $legalEntityId,
                    'entity_book_id' => $entityBookId,
                ])->save();
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
                legalEntityId: $legalEntityId,
                entityBookId: $entityBookId,
            ));

            if (! $result->success) {
                throw new \RuntimeException($result->error);
            }

            $statusBefore = $payment->payment_status;

            $payment->update([
                'journal_entry_id' => $result->journalEntryId,
                'payment_status' => 'applied',
            ]);

            // #477: settlement moves WITH the journal entry — posting is the one
            // place the invoice's paid_amount/balance/status/AR are updated. A
            // draft never touched the invoice; this is where it finally does.
            app(\App\Services\InvoiceService::class)->settlePostedPayment($payment->fresh());

            // ACC-005B: recognise the cash receipt into the settlement overlay
            // once the receipt is cash-bearing (posted).
            app(SettlementRecognitionService::class)->recognisePayment($payment->fresh());

            // #901 (PC7): a posted customer payment is a sales receipt. Written
            // to the outbox inside this transaction, published after commit.
            // Bill/vendor payments carry no customer and are not sales events.
            if ($payment->customer_id !== null) {
                app(\Modules\Sales\Services\SalesDomainEventEmitter::class)
                    ->emitPaymentReceived($payment, $statusBefore);
            }

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

                // Issue #902: the bulk UI reports the row as skipped and the
                // failure otherwise only reaches the log. Queue it with the
                // payment document, its legal entity and the post retry so an
                // accountant can see and re-drive it.
                app(\App\Platform\Exceptions\ExceptionService::class)->report(
                    new \App\Platform\Exceptions\ExceptionReport(
                        type: \App\Platform\Exceptions\ExceptionType::SALES_PAYMENT_POSTING_FAILED,
                        message: "Payment {$payment->payment_number} could not be posted: {$e->getMessage()}",
                        documentId: $payment->id,
                        documentType: 'payment',
                        legalEntityId: $payment->legal_entity_id ? (int) $payment->legal_entity_id : null,
                        payload: [
                            'payment_number' => $payment->payment_number,
                            'amount' => (float) $payment->amount,
                            'invoice_id' => $payment->invoice_id ? (int) $payment->invoice_id : null,
                            'method' => $payment->method,
                            'error' => $e->getMessage(),
                        ],
                    ),
                );

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
