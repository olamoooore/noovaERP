<?php

namespace App\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\DTOs\ReversalRequest;
use App\Models\CustomerCreditNote;
use App\Models\CustomerCreditNoteLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\TaxDeterminationResult;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

/**
 * Customer Credit Note Service
 * Handles credit note workflow and GL posting
 */
class CustomerCreditNoteService
{
    public function __construct(
        private PostingEngineInterface $postingEngine,
    ) {}

    /**
     * Create credit note with lines
     */
    public function createCreditNote(array $data): CustomerCreditNote
    {
        DB::beginTransaction();

        try {
            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $data['credit_note_date'] = $data['credit_note_date'] ?? now();
            $data['status'] = 'draft';

            // MRD pricelist-multicurrency FR-5.9: a credit note inherits the
            // INVOICE currency and rate snapshot — never its own defaults.
            $sourceInvoice = ! empty($data['invoice_id']) ? Invoice::find($data['invoice_id']) : null;

            // #511 (CN2): the credit note's customer must be the invoice's
            // customer. Two independent `exists` rules let a credit note for
            // customer A land on customer B's invoice — reject the pairing
            // (store maps RuntimeException → 422) and derive the customer
            // server-side when the caller omits it.
            if ($sourceInvoice) {
                if (empty($data['customer_id'])) {
                    $data['customer_id'] = $sourceInvoice->customer_id;
                } elseif ((int) $data['customer_id'] !== (int) $sourceInvoice->customer_id) {
                    throw new \RuntimeException('The selected invoice belongs to a different customer.');
                }
            }

            if ($sourceInvoice) {
                $data['currency_code'] = $data['currency_code'] ?? $sourceInvoice->currency_code;
                $data['exchange_rate'] = $data['exchange_rate'] ?? $sourceInvoice->exchange_rate;
                $data['base_currency_code'] = $data['base_currency_code'] ?? $sourceInvoice->base_currency_code;
            }

            $data['currency_code'] = $data['currency_code'] ?? 'USD';
            if ($sourceInvoice && empty($data['base_currency_code'])) {
                $data['base_currency_code'] = $sourceInvoice->base_currency_code;
            }
            if (empty($data['base_currency_code'])) {
                $data['base_currency_code'] = $sourceInvoice?->base_currency_code
                    ?: \App\Services\DocumentCurrencyResolver::baseFor(null, $data['customer_id'] ?? null);
            }
            if (($data['exchange_rate'] ?? null) === null) {
                $data['exchange_rate'] = 1.000000;
            }

            // #512 (CN3): totals use the SAME line arithmetic as invoices
            // (InvoiceLine::computeLineTotals → TaxEngineService::calculate)
            // with the source line's discount and tax mode carried over, then
            // the invoice's document-level discount is pro-rated by the
            // credited share — a full credit of any invoice equals that
            // invoice's total exactly, tax included.
            $sourceLines = $sourceInvoice ? $sourceInvoice->lines()->get()->keyBy('id') : collect();

            // #514 (CN5): the tax rate of a credit note line is inherited, never
            // invented by the caller. With an invoice_line_id it comes from the
            // original invoice line's determination (falling back to the rate
            // the invoice line itself was invoiced at); without one it is
            // resolved through TaxEngineService, which answers "no tax" for an
            // unconfigured tenant instead of leaving a silent 0 behind a tax
            // code. A client-supplied rate that disagrees with the source is
            // rejected — the reversal must not re-rate the original invoice.
            $sourceDeterminationLines = $sourceInvoice
                ? app(TaxDeterminationService::class)->latestDeterminationLines('invoice', (int) $sourceInvoice->id)
                : [];

            $creditNoteDate = $data['credit_note_date'];
            $creditNoteDateString = $creditNoteDate instanceof \DateTimeInterface
                ? $creditNoteDate->toDateString()
                : (string) $creditNoteDate;

            $lineTaxRates = [];

            foreach ($lines as $index => $lineData) {
                $originLine = ($lineData['invoice_line_id'] ?? null)
                    ? $sourceLines->get($lineData['invoice_line_id'])
                    : null;

                if ($originLine) {
                    // Linked line: the original invoice line's determination
                    // is the authority, and the caller may not re-rate it.
                    $originDetermination = $sourceDeterminationLines[$lineData['invoice_line_id']] ?? null;

                    $rate = $originDetermination && (float) ($originDetermination['tax_rate'] ?? 0) > 0
                        ? (float) $originDetermination['tax_rate']
                        : (float) ($originLine->tax_rate ?? 0);

                    if (isset($lineData['tax_rate']) && $lineData['tax_rate'] !== null) {
                        $supplied = (float) $lineData['tax_rate'];

                        if (abs($supplied - $rate) > 0.00001) {
                            throw new \RuntimeException(sprintf(
                                'Credit note line %d tax rate %.2f%% conflicts with the tax rate of the source document (%.2f%%). A credit note reverses the invoiced rate.',
                                $index + 1,
                                $supplied,
                                $rate
                            ));
                        }
                    }
                } elseif (isset($lineData['tax_rate']) && $lineData['tax_rate'] !== null) {
                    // Unlinked line WITH an explicit rate: the caller has its
                    // own provenance for it (e.g. the sales-order handoff
                    // carries the order line's rate across), so honour it.
                    $rate = (float) $lineData['tax_rate'];
                } else {
                    // Unlinked line WITHOUT a rate: resolve it through the tax
                    // engine rather than leaving a silent 0 behind a tax code.
                    // An unconfigured tenant still gets the engine's own
                    // "no tax" answer.
                    $resolved = app(TaxEngineService::class)->resolve(
                        product: ! empty($lineData['product_id']) ? Product::find($lineData['product_id']) : null,
                        direction: 'output',
                        date: $creditNoteDateString,
                    );

                    $rate = (float) ($resolved['rate'] ?? 0);
                }

                $lineTaxRates[$index] = $rate;
            }

            $subtotal = 0.0;
            $lineTaxSum = 0.0;

            foreach ($lines as $index => $lineData) {
                $origin = ($lineData['invoice_line_id'] ?? null) ? $sourceLines->get($lineData['invoice_line_id']) : null;

                $calc = InvoiceLine::computeLineTotals(
                    (float) $lineData['quantity'],
                    (float) $lineData['unit_price'],
                    (float) $lineTaxRates[$index],
                    (bool) ($lineData['tax_inclusive'] ?? $origin?->tax_inclusive ?? false),
                    (float) ($lineData['discount_amount'] ?? $origin?->discount_amount ?? 0),
                );

                $subtotal += $calc['net'];
                $lineTaxSum += $calc['tax_amount'];
            }

            $subtotal = round($subtotal, 2);
            $lineTaxSum = round($lineTaxSum, 2);

            $docDiscount = 0.0;
            if ($sourceInvoice
                && (float) $sourceInvoice->discount_amount > 0
                && (float) $sourceInvoice->subtotal > 0
                && $subtotal > 0) {
                $docDiscount = round(
                    (float) $sourceInvoice->discount_amount * min(1.0, $subtotal / (float) $sourceInvoice->subtotal),
                    2,
                );
            }

            $taxAmount = ($docDiscount > 0 && $subtotal > 0)
                ? round($lineTaxSum * (max($subtotal - $docDiscount, 0) / $subtotal), 2)
                : $lineTaxSum;

            $data['discount_amount'] = $docDiscount;
            $data['subtotal'] = $subtotal;
            $data['tax_amount'] = $taxAmount;
            $data['total_amount'] = round($subtotal - $docDiscount + $taxAmount, 2);

            // Let the model's creating callback handle created_by attribution
            if (empty($data['created_by'])) {
                $data['created_by'] = TenantUserHelper::resolveTenantUserId(Auth::id());
            } else {
                $data['created_by'] = TenantUserHelper::requireTenantUserId($data['created_by']);
            }

            $creditNote = CustomerCreditNote::create($data);

            // Add lines — provenance (discount, tax mode, pricing) carries
            // over from the source invoice line when the payload omits it.
            foreach ($lines as $index => $lineData) {
                $origin = ($lineData['invoice_line_id'] ?? null) ? $sourceLines->get($lineData['invoice_line_id']) : null;

                $creditNote->lines()->create([
                    'invoice_line_id' => $lineData['invoice_line_id'] ?? null,
                    'product_id' => $lineData['product_id'] ?? null,
                    'description' => $lineData['description'],
                    'quantity' => $lineData['quantity'],
                    'unit_price' => $lineData['unit_price'],
                    'base_unit_price' => $lineData['base_unit_price']
                        ?? ($origin?->base_unit_price != null
                            ? (float) $origin->base_unit_price
                            : round((float) $lineData['unit_price'] * (float) ($data['exchange_rate'] ?: 1), 4)),
                    'pricing_trace' => $lineData['pricing_trace'] ?? $origin?->pricing_trace,
                    'tax_rate' => $lineTaxRates[$index],
                    'tax_inclusive' => (bool) ($lineData['tax_inclusive'] ?? $origin?->tax_inclusive ?? false),
                    'discount_amount' => (float) ($lineData['discount_amount'] ?? $origin?->discount_amount ?? 0),
                    'account_id' => $lineData['account_id'] ?? null,
                ]);
            }

            DB::commit();

            return $creditNote->load(['customer', 'invoice', 'lines.product']);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Post credit note (creates journal entry)
     * DR: Sales Revenue
     * DR: Sales Tax Payable
     * CR: Accounts Receivable
     */
    public function postCreditNote(CustomerCreditNote $creditNote): CustomerCreditNote
    {
        if ($creditNote->status !== 'draft') {
            throw new \Exception('Only draft credit notes can be posted');
        }

        // #511 (CN2): re-enforce the pairing at post time — legacy rows or
        // direct model writes must not reach the ledger with a customer that
        // does not own the invoice.
        if ($creditNote->invoice && (int) $creditNote->invoice->customer_id !== (int) $creditNote->customer_id) {
            throw new \RuntimeException('Credit note customer does not match the invoice customer.');
        }

        DB::beginTransaction();

        try {
            // #513 (CN4): the credit caps are enforced HERE — inside the
            // posting transaction, against the invoice row locked with
            // lockForUpdate(). Checking before the transaction (and without a
            // lock) let a line be credited beyond what was invoiced and let
            // two concurrent posts both pass a stale snapshot.
            $invoice = Invoice::whereKey($creditNote->invoice_id)->lockForUpdate()->firstOrFail();
            $this->assertCreditWithinLimits($creditNote, $invoice);

            $lines = [];

            // MRD TAX-REQ-07 (D4): the credit note must reverse the ORIGINAL
            // invoice's tax identity, not just the amount. Resolve the tax
            // identity per line from the persisted invoice determination
            // (keyed by invoice_line_id) and build a canonical, persisted
            // credit-note determination so the reversal lands in
            // tax_transaction_details and nets on the statutory return
            // (TaxReturnAggregator signs credit_note rows negative).
            $determinationService = app(TaxDeterminationService::class);
            $creditNote->loadMissing('lines.product');
            $originalLines = $determinationService->latestDeterminationLines('invoice', (int) $creditNote->invoice_id);
            $originalResult = TaxDeterminationResult::where('source_record_type', 'invoice')
                ->where('source_record_id', (int) $creditNote->invoice_id)
                ->current()
                ->orderByDesc('result_version')
                ->first();

            $cnLines = [];
            foreach ($creditNote->lines as $line) {
                $origin = $line->invoice_line_id !== null ? ($originalLines[$line->invoice_line_id] ?? null) : null;

                // #514 (CN5): the reversal takes ONE rate — the original
                // invoice line's determination rate when there is one, else the
                // rate the credit note line was created with (itself resolved
                // server-side at creation). The booked amount must be computed
                // with that same rate: taking the rate from the determination
                // while computing the amount from the credit note line's own
                // rate is what made the tax code, the rate and the amount
                // disagree.
                $rate = $origin && (float) ($origin['tax_rate'] ?? 0) > 0
                    ? (float) $origin['tax_rate']
                    : (float) ($line->tax_rate ?? 0);

                // #512 (CN3): the shared line arithmetic — net base and tax
                // honour the line's discount and tax mode (the old call
                // forced exclusive, undiscounted tax on top).
                $calc = InvoiceLine::computeLineTotals(
                    (float) $line->quantity,
                    (float) $line->unit_price,
                    $rate,
                    (bool) $line->tax_inclusive,
                    (float) ($line->discount_amount ?? 0),
                );

                $cnLines[] = [
                    'source_line_id' => $line->id,
                    'product' => $line->product,
                    'base' => $calc['net'],
                    'rate' => $rate,
                    'tax' => $calc['tax_amount'],
                    'tax_code_override' => $origin['tax_code'] ?? null,
                    'treatment_override' => $origin['treatment'] ?? null,
                    'tax_id_override' => $origin['tax_id'] ?? null,
                ];
            }

            $taxDetermination = null;
            $bookedTaxTotal = round((float) $creditNote->tax_amount, 2);

            if ($bookedTaxTotal > 0 && $cnLines !== []) {
                $taxDetermination = $determinationService->determineForLineSet(
                    documentType: 'credit_note',
                    documentId: (int) $creditNote->id,
                    date: $creditNote->credit_note_date->toDateString(),
                    lines: $cnLines,
                    direction: 'output',
                    jurisdiction: $originalResult?->jurisdiction,
                    customerId: $creditNote->customer_id,
                );
            }

            // MRD pricelist-multicurrency FR-5.9/FR-7.5: the credit note
            // reverses at the INVOICE's snapshot rate — its own column was
            // inherited at creation; convert doc amounts to base there and
            // carry the original side on the GL lines.
            $cnDocCurrency = strtoupper((string) ($creditNote->currency_code ?: 'USD'));
            $cnBaseCurrency = strtoupper((string) ($creditNote->base_currency_code
                ?: $invoice?->base_currency_code
                ?: \App\Services\DocumentCurrencyResolver::baseFor(null, $creditNote->customer_id)));
            $cnForeign = $cnDocCurrency !== $cnBaseCurrency;
            $cnRate = $cnForeign
                ? (float) ($creditNote->exchange_rate
                    ?? $invoice?->exchange_rate
                    ?? \App\Services\DocumentCurrencyResolver::snapshot(
                        $cnDocCurrency,
                        $creditNote->customer_id,
                        null,
                        $creditNote->credit_note_date->toDateString()
                    )['exchange_rate'])
                : 1.0;

            if ($cnForeign && ($creditNote->exchange_rate === null || $creditNote->base_currency_code === null)) {
                $creditNote->update([
                    'exchange_rate' => $cnRate,
                    'base_currency_code' => $cnBaseCurrency,
                ]);
            }

            $cnToBase = static fn (float $docAmount): float => $cnForeign
                ? round($docAmount * $cnRate, 2)
                : round($docAmount, 2);

            $cnTaxBaseTotal = 0.0;

            // DR Sales Revenue (subtotal)
            $lines = [];

            // DR Sales Tax Payable — one line per reversed tax_code (built
            // first so the revenue leg can absorb the rounding residual,
            // same §9.4 rule as invoice posting).
            if ($bookedTaxTotal > 0) {
                foreach (TaxDeterminationService::allocateBookedTax($taxDetermination['tax_lines'] ?? [], $bookedTaxTotal) as $allocation) {
                    if ($allocation['amount'] <= 0) {
                        continue;
                    }
                    $taxBase = $cnToBase((float) $allocation['amount']);
                    $cnTaxBaseTotal += $taxBase;
                    $lines[] = new PostingRequestLine(
                        accountMappingKey: 'sales_tax_payable',
                        accountId: null,
                        debit: $taxBase,
                        credit: 0,
                        description: "Tax - Credit Note {$creditNote->credit_note_number}".($allocation['tax_code'] ? " [{$allocation['tax_code']}]" : ''),
                        taxCode: $allocation['tax_code'],
                        taxId: $allocation['tax_id'],
                        currencyCode: $cnForeign ? $cnBaseCurrency : null,
                        originalAmount: $cnForeign ? round((float) $allocation['amount'], 2) : null,
                        exchangeRate: $cnForeign ? $cnRate : null,
                    );
                }
            }

            $cnArBase = $cnToBase((float) $creditNote->total_amount);
            $cnRevenueBase = round($cnArBase - $cnTaxBaseTotal, 2);

            $lines[] = new PostingRequestLine(
                accountMappingKey: 'sales_revenue',
                accountId: null,
                debit: $cnRevenueBase,
                credit: 0,
                description: "Credit Note {$creditNote->credit_note_number}",
                currencyCode: $cnForeign ? $cnBaseCurrency : null,
                originalAmount: $cnForeign ? round((float) $creditNote->subtotal, 2) : null,
                exchangeRate: $cnForeign ? $cnRate : null,
            );

            // CR Accounts Receivable (total)
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'accounts_receivable',
                accountId: null,
                debit: 0,
                credit: $cnArBase,
                description: "Credit Note {$creditNote->credit_note_number}".($cnForeign ? " ({$creditNote->total_amount} {$cnDocCurrency} @ {$cnRate})" : ''),
                currencyCode: $cnForeign ? $cnBaseCurrency : null,
                originalAmount: $cnForeign ? round((float) $creditNote->total_amount, 2) : null,
                exchangeRate: $cnForeign ? $cnRate : null,
            );

            $result = $this->postingEngine->post(new PostingRequest(
                documentType: 'credit_note',
                documentId: $creditNote->id,
                postingDate: $creditNote->credit_note_date->toDateString(),
                description: "Credit Note #{$creditNote->credit_note_number} - {$creditNote->customer->name}",
                currencyCode: $cnForeign ? $cnDocCurrency : null,
                exchangeRate: $cnForeign ? $cnRate : null,
                lines: $lines,
                reference: $creditNote->credit_note_number,
                userId: TenantUserHelper::requireTenantUserId($creditNote->created_by),
                taxDeterminationResult: $taxDetermination,
            ));

            if (! $result->success) {
                throw new \Exception($result->error);
            }

            $creditNote->update([
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => TenantUserHelper::requireTenantUserId($creditNote->created_by),
                'journal_entry_id' => $result->journalEntryId,
            ]);

            // #510 (CN1): a credit note reduces what is OWED, it is not a cash
            // refund — apply it through the credit path (credited_amount +
            // balance = total − paid − credited), never via paid_amount.
            app(InvoiceService::class)->applyCreditNoteToInvoice($invoice, (float) $creditNote->total_amount);

            DB::commit();

            return $creditNote->fresh(['journalEntry', 'lines', 'customer', 'invoice']);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * #513 (CN4): the credit caps, enforced with the invoice row already
     * locked inside the posting transaction.
     *
     *  - Document level: credits already posted + this note <= invoice total.
     *  - Per linked invoice line: credited quantity <= invoiced quantity and
     *    credited net <= invoiced net, counting earlier POSTED credit notes.
     *
     * Lines without an invoice_line_id have no invoiced quantity or price to
     * compare against, so only the document cap above covers them.
     *
     * Throws RuntimeException — the API maps that to 422 (a validation error),
     * not a 500.
     */
    private function assertCreditWithinLimits(CustomerCreditNote $creditNote, Invoice $invoice): void
    {
        $priorNoteIds = CustomerCreditNote::where('invoice_id', $invoice->id)
            ->where('status', 'posted')
            ->where('id', '!=', $creditNote->id)
            ->pluck('id');

        $alreadyCredited = $priorNoteIds->isEmpty()
            ? 0.0
            : (float) CustomerCreditNote::whereIn('id', $priorNoteIds)->sum('total_amount');

        if ($alreadyCredited + (float) $creditNote->total_amount > (float) $invoice->total) {
            throw new \RuntimeException(
                'Credit note amount exceeds invoice amount. '.
                "Invoice: {$invoice->total}, Already credited: {$alreadyCredited}, ".
                "This credit note: {$creditNote->total_amount}"
            );
        }

        $priorLines = $priorNoteIds->isEmpty()
            ? collect()
            : CustomerCreditNoteLine::whereIn('credit_note_id', $priorNoteIds)
                ->whereNotNull('invoice_line_id')
                ->get()
                ->groupBy('invoice_line_id');

        foreach ($creditNote->lines as $line) {
            if ($line->invoice_line_id === null) {
                continue;
            }

            $origin = InvoiceLine::find($line->invoice_line_id);

            if (! $origin || (int) $origin->invoice_id !== (int) $invoice->id) {
                throw new \RuntimeException(
                    "Credit note line references invoice line {$line->invoice_line_id}, which does not belong to invoice {$invoice->id}."
                );
            }

            $prior = $priorLines->get($line->invoice_line_id) ?? collect();

            $creditedQuantity = (float) $line->quantity
                + (float) $prior->sum(fn ($l) => (float) $l->quantity);

            if ($creditedQuantity > (float) $origin->quantity + 0.00001) {
                throw new \RuntimeException(sprintf(
                    'Credit note line quantity %.2f exceeds invoiced quantity %.2f on invoice line %d (already credited %.2f).',
                    $creditedQuantity,
                    (float) $origin->quantity,
                    (int) $origin->id,
                    $creditedQuantity - (float) $line->quantity
                ));
            }

            // line_total is gross of tax on both documents, so the comparable
            // figure is the net (gross - tax).
            $originNet = (float) $origin->line_total - (float) $origin->tax_amount;
            $thisNet = (float) $line->line_total - (float) $line->tax_amount;
            $priorNet = (float) $prior->sum(fn ($l) => (float) $l->line_total - (float) $l->tax_amount);

            if ($thisNet + $priorNet > $originNet + 0.0001) {
                throw new \RuntimeException(sprintf(
                    'Credit note line amount %.2f exceeds invoiced line amount %.2f on invoice line %d (already credited %.2f).',
                    $thisNet + $priorNet,
                    $originNet,
                    (int) $origin->id,
                    $priorNet
                ));
            }
        }
    }

    /**
     * Issue #228: void a posted credit note — the escape hatch for the #169
     * guard that blocks invoice cancel/reset-to-draft while a posted credit
     * note exists.
     *
     * Reverses the credit note's journal entry (via the Posting Engine, so the
     * ledger stays the single source of truth), restores the invoice balance
     * the posting reduced, and transitions the note to 'voided'. Idempotency of
     * the GL reversal itself is handled by PostingEngine::reverse().
     */
    public function voidCreditNote(CustomerCreditNote $creditNote): CustomerCreditNote
    {
        if ($creditNote->status !== 'posted') {
            throw new \Exception('Only posted credit notes can be voided');
        }

        $actorId = TenantUserHelper::requireTenantUserId(Auth::id() ?? $creditNote->posted_by);

        DB::beginTransaction();

        try {
            $reversalEntryId = null;

            if ($creditNote->journal_entry_id) {
                $reversal = $this->postingEngine->reverse(new ReversalRequest(
                    journalEntryId: (int) $creditNote->journal_entry_id,
                    reversalDate: now()->toDateString(),
                    reason: "Void Credit Note {$creditNote->credit_note_number}",
                    reasonCode: 'CORRECTION',
                    actorUserId: $actorId,
                    tenantId: tenant()->id ?? null,
                ));

                if (! $reversal->success) {
                    throw new \Exception($reversal->error ?? 'Failed to reverse credit note journal entry');
                }

                $reversalEntryId = $reversal->journalEntryId
                    ?? $reversal->journalEntry?->id;
            }

            // #510 (CN1): release the credit applied at posting time.
            if ($creditNote->invoice) {
                app(InvoiceService::class)->reverseCreditNoteFromInvoice(
                    $creditNote->invoice,
                    (float) $creditNote->total_amount,
                );
            }

            $creditNote->update([
                'status' => 'voided',
                'voided_at' => now(),
                'voided_by' => $actorId,
                'reversal_journal_entry_id' => $reversalEntryId,
            ]);

            DB::commit();

            return $creditNote->fresh(['journalEntry', 'lines', 'customer', 'invoice']);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Validate credit note can be created for invoice
     */
    public function validateCreditNote(int $invoiceId, float $amount): array
    {
        $invoice = \App\Models\Invoice::findOrFail($invoiceId);

        $existingCreditNotes = CustomerCreditNote::where('invoice_id', $invoiceId)
            ->where('status', 'posted')
            ->sum('total_amount');

        $remainingAmount = $invoice->total - $existingCreditNotes;

        return [
            'valid' => $amount <= $remainingAmount,
            'invoice_amount' => $invoice->total,
            'already_credited' => $existingCreditNotes,
            'remaining_amount' => $remainingAmount,
            'requested_amount' => $amount,
        ];
    }
}
