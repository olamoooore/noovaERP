<?php

namespace App\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\DTOs\ReversalRequest;
use App\Accounting\Events\CreditNotePosted;
use App\Models\CustomerCreditNote;
use App\Models\CustomerCreditNoteLine;
use App\Models\DeferredRevenueSchedule;
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
    /** #527: credit note reversal wizard modes that post the credit note. */
    public const REVERSAL_FULL_REFUND = 'full_refund';

    public const REVERSAL_FULL_REFUND_NEW_DRAFT = 'full_refund_new_draft';

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

            if ($sourceInvoice && empty($data['base_currency_code'])) {
                $data['base_currency_code'] = $sourceInvoice->base_currency_code;
            }
            if (empty($data['base_currency_code'])) {
                $data['base_currency_code'] = $sourceInvoice?->base_currency_code
                    ?: \App\Services\DocumentCurrencyResolver::baseFor(null, $data['customer_id'] ?? null);
            }
            // #521 (CN12): a legacy/imported invoice with no currency_code
            // means the document is in the tenant's base currency — never a
            // hard-coded 'USD', which booked an MYR tenant's credit note as a
            // foreign-currency document at a 1.0 rate.
            if (empty($data['currency_code'])) {
                $data['currency_code'] = $data['base_currency_code'];
            }
            if (($data['exchange_rate'] ?? null) === null) {
                $data['exchange_rate'] = 1.000000;
            }

            $calculated = $this->calculateLines($sourceInvoice, $lines, $data['credit_note_date'], $data['exchange_rate']);
            $data = array_merge($data, $calculated['totals']);

            // Let the model's creating callback handle created_by attribution
            if (empty($data['created_by'])) {
                $data['created_by'] = TenantUserHelper::resolveTenantUserId(Auth::id());
            } else {
                $data['created_by'] = TenantUserHelper::requireTenantUserId($data['created_by']);
            }

            $creditNote = CustomerCreditNote::create($data);

            // Add lines — provenance (discount, tax mode, pricing) carries
            // over from the source invoice line when the payload omits it.
            foreach ($calculated['lines'] as $row) {
                $creditNote->lines()->create($row);
            }

            DB::commit();

            return $creditNote->load(['customer', 'invoice', 'lines.product']);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * #518 (CN9): update a DRAFT credit note. Header fields are applied as
     * given; when `lines` is present the draft's lines are replaced as a whole
     * and the document totals are recalculated through calculateLines() — the
     * same path createCreditNote() uses — so CN3 arithmetic and CN5 tax-rate
     * provenance hold for edits too. CN2 (customer ↔ invoice pairing) is
     * re-checked; CN4 credit caps stay enforced at post time under the
     * invoice row lock. Only drafts are editable; the row is locked so a
     * concurrent post cannot slip between the status check and the write.
     */
    public function updateDraftCreditNote(CustomerCreditNote $creditNote, array $data): CustomerCreditNote
    {
        return DB::transaction(function () use ($creditNote, $data) {
            $creditNote = CustomerCreditNote::whereKey($creditNote->id)->lockForUpdate()->firstOrFail();

            if ($creditNote->status !== 'draft') {
                throw new \RuntimeException('Only draft credit notes can be edited');
            }

            $lines = $data['lines'] ?? null;
            unset($data['lines']);

            $creditNote->fill($data);

            if ($lines !== null) {
                $sourceInvoice = $creditNote->invoice_id ? Invoice::find($creditNote->invoice_id) : null;

                if ($sourceInvoice && (int) $sourceInvoice->customer_id !== (int) $creditNote->customer_id) {
                    throw new \RuntimeException('Credit note customer does not match the invoice customer.');
                }

                $calculated = $this->calculateLines(
                    $sourceInvoice,
                    $lines,
                    $creditNote->credit_note_date ?? now(),
                    $creditNote->exchange_rate,
                );

                $creditNote->fill($calculated['totals']);

                $creditNote->lines()->delete();
                foreach ($calculated['lines'] as $row) {
                    $creditNote->lines()->create($row);
                }
            }

            $creditNote->save();

            return $creditNote->fresh(['customer', 'invoice', 'lines']);
        });
    }

    /**
     * The shared credit-note line calculation — used at create time and when
     * a draft's lines are edited (#518 / CN9), so both paths apply the same
     * rules: CN5 tax-rate provenance (inherited from the source invoice line,
     * never re-rated by the caller), CN3 line arithmetic
     * (InvoiceLine::computeLineTotals) and the pro-rated document discount.
     *
     * Returns the document totals and the line rows ready to persist.
     *
     * @return array{totals: array<string, float>, lines: array<int, array<string, mixed>>}
     */
    private function calculateLines(?Invoice $sourceInvoice, array $lines, mixed $creditNoteDate, mixed $exchangeRate): array
    {
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

        // Add lines — provenance (discount, tax mode, pricing) carries
        // over from the source invoice line when the payload omits it.
        $rows = [];
        foreach ($lines as $index => $lineData) {
            $origin = ($lineData['invoice_line_id'] ?? null) ? $sourceLines->get($lineData['invoice_line_id']) : null;

            $rows[] = [
                'invoice_line_id' => $lineData['invoice_line_id'] ?? null,
                'product_id' => $lineData['product_id'] ?? null,
                'description' => $lineData['description'],
                'quantity' => $lineData['quantity'],
                'unit_price' => $lineData['unit_price'],
                'base_unit_price' => $lineData['base_unit_price']
                    ?? ($origin?->base_unit_price != null
                        ? (float) $origin->base_unit_price
                        : round((float) $lineData['unit_price'] * (float) ($exchangeRate ?: 1), 4)),
                'pricing_trace' => $lineData['pricing_trace'] ?? $origin?->pricing_trace,
                'tax_rate' => $lineTaxRates[$index],
                'tax_inclusive' => (bool) ($lineData['tax_inclusive'] ?? $origin?->tax_inclusive ?? false),
                'discount_amount' => (float) ($lineData['discount_amount'] ?? $origin?->discount_amount ?? 0),
                'account_id' => $lineData['account_id'] ?? null,
            ];
        }

        return [
            'totals' => [
                'discount_amount' => $docDiscount,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => round($subtotal - $docDiscount + $taxAmount, 2),
            ],
            'lines' => $rows,
        ];
    }

    /**
     * Post credit note (creates journal entry)
     * DR: Sales Revenue
     * DR: Sales Tax Payable
     * CR: Accounts Receivable
     */
    public function postCreditNote(CustomerCreditNote $creditNote): CustomerCreditNote
    {
        // #516 (CN7): captured before the posting work, which tears the
        // tenancy context down before the post-commit dispatch below.
        $postingTenantId = tenancy()->tenant?->id;

        // #517 (CN8): the audit actor is the user who POSTS, not the user who
        // drafted. Previously the creator was passed as the preferred id, so
        // whenever the acting user could not be resolved by email in the
        // tenant user table the ledger silently attributed the posting to the
        // creator. Non-interactive posts (scheduler, queue) have no acting
        // user and fall back to the creator.
        $actorId = Auth::id() !== null
            ? TenantUserHelper::requireTenantUserId(Auth::id())
            : TenantUserHelper::requireTenantUserId($creditNote->created_by);

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
                    // Issue #882 (SA9): the source invoice line lets the
                    // reversal find the deferred-revenue schedule (if any).
                    'invoice_line_id' => $line->invoice_line_id,
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
            $cnBaseCurrency = strtoupper((string) ($creditNote->base_currency_code
                ?: $invoice?->base_currency_code
                ?: \App\Services\DocumentCurrencyResolver::baseFor(null, $creditNote->customer_id)));
            // #521 (CN12): no document currency → the base currency, not 'USD'.
            $cnDocCurrency = strtoupper((string) ($creditNote->currency_code ?: $cnBaseCurrency));
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

            // Issue #882 (SA9): reverse the UNRELEASED balance from deferred
            // revenue first; only the already-recognised portion is debited
            // to income.
            foreach ($this->buildRevenueReversalLines(
                $creditNote,
                $cnLines,
                $cnRevenueBase,
                $cnForeign,
                $cnBaseCurrency,
                $cnRate,
            ) as $revenueLine) {
                $lines[] = $revenueLine;
            }

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

            // Issue #875: bind the credit note posting to the source invoice's legal
            // entity (and entity book), stamping the note when it predates the
            // entity columns.
            $entityResolver = app(\App\Services\LegalEntityContextResolver::class);
            $legalEntityId = $entityResolver->resolveEntity(
                explicit: $creditNote->legal_entity_id ? (int) $creditNote->legal_entity_id : null,
                salesOrderId: $creditNote->sales_order_id ? (int) $creditNote->sales_order_id : null,
                invoiceId: $creditNote->invoice_id ? (int) $creditNote->invoice_id : null,
                customerId: $creditNote->customer_id ? (int) $creditNote->customer_id : null,
                required: true,
            );
            $entityBookId = $creditNote->entity_book_id
                ? (int) $creditNote->entity_book_id
                : $entityResolver->resolveBookId($legalEntityId);

            if ($legalEntityId && (int) $creditNote->legal_entity_id !== $legalEntityId) {
                $creditNote->forceFill([
                    'legal_entity_id' => $legalEntityId,
                    'entity_book_id' => $entityBookId,
                ])->save();
            }

            $result = $this->postingEngine->post(new PostingRequest(
                documentType: 'credit_note',
                documentId: $creditNote->id,
                postingDate: $creditNote->credit_note_date->toDateString(),
                description: "Credit Note #{$creditNote->credit_note_number} - {$creditNote->customer->name}",
                currencyCode: $cnForeign ? $cnDocCurrency : null,
                exchangeRate: $cnForeign ? $cnRate : null,
                lines: $lines,
                reference: $creditNote->credit_note_number,
                userId: $actorId,
                taxDeterminationResult: $taxDetermination,
                legalEntityId: $legalEntityId,
                entityBookId: $entityBookId,
            ));

            if (! $result->success) {
                // #278: a closed tax period is a governed refusal, not a fault.
                if ($result->reasonCode === \App\Accounting\Exceptions\TaxPeriodLockedException::REASON_CODE) {
                    throw new \App\Accounting\Exceptions\TaxPeriodLockedException($result->error);
                }

                throw new \Exception($result->error);
            }

            $creditNote->update([
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => $actorId,
                'journal_entry_id' => $result->journalEntryId,
            ]);

            // #510 (CN1): a credit note reduces what is OWED, it is not a cash
            // refund — apply it through the credit path (credited_amount +
            // balance = total − paid − credited), never via paid_amount.
            app(InvoiceService::class)->applyCreditNoteToInvoice($invoice, (float) $creditNote->total_amount);

            // #901 (PC7): the business event is written to the outbox inside
            // this transaction and published only after it commits.
            app(\Modules\Sales\Services\SalesDomainEventEmitter::class)
                ->emitCreditNotePosted($creditNote);

            DB::commit();

            // #516 (CN7): after the commit, mirroring InvoicePosted — the
            // e-invoicing listener must only ever see a credit note whose
            // ledger effect is durable.
            CreditNotePosted::dispatch(
                $creditNote->fresh(['journalEntry', 'lines', 'customer', 'invoice']),
                $postingTenantId
            );

            return $creditNote->fresh(['journalEntry', 'lines', 'customer', 'invoice']);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Issue #882 (SA9): build the revenue-side debit lines of a credit note.
     *
     * For a line linked to an open deferred-revenue schedule, the reversal
     * debits the schedule's deferred account for its still-unreleased balance;
     * only the already-recognised portion hits income. Shares are allocated
     * over the credit note's lines with the same whole-cent arithmetic invoice
     * posting uses (#876), so the debits sum EXACTLY to the revenue reversal
     * and the entry stays balanced.
     *
     * When no line is linked to an open schedule the single legacy
     * `sales_revenue` debit is posted byte-identically to before.
     *
     * @param  array<int, array<string, mixed>>  $cnLines
     * @return PostingRequestLine[]
     */
    private function buildRevenueReversalLines(
        CustomerCreditNote $creditNote,
        array $cnLines,
        float $revenueBase,
        bool $foreign,
        string $baseCurrency,
        float $exchangeRate,
    ): array {
        $revenueBase = round($revenueBase, 2);

        if ($revenueBase == 0.0) {
            return [];
        }

        $weights = [];
        $scheduledLines = [];

        foreach ($cnLines as $index => $cnLine) {
            $weights[$index] = max(round((float) ($cnLine['base'] ?? 0), 2), 0.0);

            $schedule = ! empty($cnLine['invoice_line_id'])
                ? DeferredRevenueSchedule::where('invoice_line_id', $cnLine['invoice_line_id'])->first()
                : null;

            if ($schedule && $schedule->remainingAmount() > 0) {
                $scheduledLines[$index] = $schedule;
            }
        }

        if ($scheduledLines === []) {
            return [new PostingRequestLine(
                accountMappingKey: 'sales_revenue',
                accountId: null,
                debit: $revenueBase,
                credit: 0,
                description: "Credit Note {$creditNote->credit_note_number}",
                currencyCode: $foreign ? $baseCurrency : null,
                originalAmount: $foreign ? round((float) $creditNote->subtotal, 2) : null,
                exchangeRate: $foreign ? $exchangeRate : null,
            )];
        }

        $weights = array_values($weights);
        $shares = \App\Support\CentsAllocation::allocate((int) round($revenueBase * 100), $weights);
        $documentShares = $foreign
            ? \App\Support\CentsAllocation::allocate(
                (int) round(round((float) $creditNote->subtotal - (float) $creditNote->discount_amount, 2) * 100),
                $weights,
            )
            : [];

        $recognition = app(DeferredRevenueRecognitionService::class);
        $lines = [];
        $deferredBase = 0.0;
        $deferredDocument = 0.0;

        foreach ($scheduledLines as $index => $schedule) {
            $share = round($shares[$index] / 100, 2);
            $release = $recognition->applyCreditReversal($schedule, $share);

            if ($release <= 0) {
                continue;
            }

            $documentShare = $foreign ? round($documentShares[$index] / 100, 2) : null;
            $deferredBase = round($deferredBase + $release, 2);
            $deferredDocument = $foreign ? round($deferredDocument + $documentShare, 2) : 0.0;

            $lines[] = new PostingRequestLine(
                accountMappingKey: 'deferred_revenue',
                accountId: (int) $schedule->deferred_account_id,
                debit: $release,
                credit: 0,
                description: "Credit Note {$creditNote->credit_note_number} - deferred revenue reversal",
                currencyCode: $foreign ? $baseCurrency : null,
                originalAmount: $documentShare,
                exchangeRate: $foreign ? $exchangeRate : null,
            );
        }

        $incomeBase = round($revenueBase - $deferredBase, 2);

        if ($incomeBase != 0.0) {
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'sales_revenue',
                accountId: null,
                debit: $incomeBase,
                credit: 0,
                description: "Credit Note {$creditNote->credit_note_number}",
                currencyCode: $foreign ? $baseCurrency : null,
                originalAmount: $foreign
                    ? round((float) $creditNote->subtotal - $deferredDocument, 2)
                    : null,
                exchangeRate: $foreign ? $exchangeRate : null,
            );
        }

        return $lines;
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
    public function voidCreditNote(CustomerCreditNote $creditNote, ?string $reason = null): CustomerCreditNote
    {
        if ($creditNote->status !== 'posted') {
            throw new \Exception('Only posted credit notes can be voided');
        }

        // #528: credit already paid out to the customer cannot be released by
        // a void — the refund must be voided first (its journal reversed),
        // otherwise the note's invoice would lose credit it no longer holds.
        if ($creditNote->refunds()->whereNull('voided_at')->exists()) {
            throw new \InvalidArgumentException(
                "Credit note {$creditNote->credit_note_number} has been refunded to the customer. Void the refund before voiding the credit note."
            );
        }

        $actorId = TenantUserHelper::requireTenantUserId(Auth::id() ?? $creditNote->posted_by);

        DB::beginTransaction();

        try {
            $reversalEntryId = null;

            if ($creditNote->journal_entry_id) {
                $reversal = $this->postingEngine->reverse(new ReversalRequest(
                    journalEntryId: (int) $creditNote->journal_entry_id,
                    reversalDate: now()->toDateString(),
                    reason: "Void Credit Note {$creditNote->credit_note_number}"
                        .($reason !== null && $reason !== '' ? ": {$reason}" : ''),
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

            // #495: first take back any of the note's credit applied to other
            // invoices (outstanding credits), so none keeps a voided credit.
            app(PaymentAllocationService::class)->reverseCreditNoteApplications($creditNote);

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
     * #527 (SALES-ODOO): the "full refund" modes of the credit note reversal
     * wizard. The partial mode is the #522 dialog — a DRAFT credit note the
     * user edits — and goes through createCreditNote() on its own.
     *
     *  - full_refund: credit everything not yet credited on every invoice
     *    line, and post it, which reconciles it with the invoice
     *    (applyCreditNoteToInvoice: balance = total − paid − credited).
     *  - full_refund_new_draft: the same, plus a new DRAFT invoice copied
     *    from the original (ReplacementDraftService) and linked to the
     *    credit note, for the user to correct and re-issue.
     *
     * The lines and amounts are built here from the invoice, never taken
     * from the caller, using the same "already credited" rule the #513 cap
     * enforces (POSTED credit notes only). One unit of work: if posting
     * fails, neither the credit note nor the new draft survive. The credit
     * note is posted LAST, so CreditNotePosted (dispatched at the end of
     * postCreditNote) is the final step before the commit.
     *
     * Throws RuntimeException (the API maps it to 422) for an invoice that
     * cannot be credited or has nothing left to credit.
     *
     * @return array{credit_note: CustomerCreditNote, invoice: ?Invoice}
     */
    public function reverseInvoice(Invoice $invoice, string $mode, array $data = []): array
    {
        if (! in_array($mode, [self::REVERSAL_FULL_REFUND, self::REVERSAL_FULL_REFUND_NEW_DRAFT], true)) {
            throw new \InvalidArgumentException("Unknown credit note reversal mode: {$mode}");
        }

        return DB::transaction(function () use ($invoice, $mode, $data) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (in_array($invoice->status, ['draft', 'void', 'cancelled'], true)
                || in_array($invoice->lifecycle_status, ['voided', 'reversed', 'superseded'], true)) {
                throw new \RuntimeException('Only a posted invoice can be credited.');
            }

            $lines = $this->remainingCreditLines($invoice);

            if ($lines === []) {
                throw new \RuntimeException('Every line of this invoice has already been credited in full.');
            }

            $creditNote = $this->createCreditNote([
                'customer_id' => $invoice->customer_id,
                'invoice_id' => $invoice->id,
                'credit_note_date' => $data['credit_note_date'] ?? null,
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'lines' => $lines,
            ]);

            $reissued = null;
            if ($mode === self::REVERSAL_FULL_REFUND_NEW_DRAFT) {
                $reissued = app(ReplacementDraftService::class)->createInvoiceReissueDraft(
                    $invoice,
                    $creditNote,
                    new CorrectionRequest(
                        userId: Auth::id(),
                        reasonCode: 'credit_note_reissue',
                        reasonText: $data['reason'] ?? null,
                    ),
                );
            }

            $creditNote = $this->postCreditNote($creditNote);

            return [
                'credit_note' => $creditNote,
                'invoice' => $reissued?->fresh(['lines']),
            ];
        });
    }

    /**
     * #527: what is still creditable on each invoice line — the invoiced
     * quantity and line discount minus what POSTED credit notes already
     * credited (the #513 / #522 rule). Tax rate and tax mode are inherited
     * from the invoice line by calculateLines().
     *
     * @return array<int, array<string, mixed>>
     */
    private function remainingCreditLines(Invoice $invoice): array
    {
        $postedNoteIds = CustomerCreditNote::where('invoice_id', $invoice->id)
            ->where('status', 'posted')
            ->pluck('id');

        $prior = $postedNoteIds->isEmpty()
            ? collect()
            : CustomerCreditNoteLine::whereIn('credit_note_id', $postedNoteIds)
                ->whereNotNull('invoice_line_id')
                ->get()
                ->groupBy('invoice_line_id');

        $lines = [];
        foreach ($invoice->lines()->orderBy('id')->get() as $line) {
            if (in_array($line->line_type ?? 'product', ['note', 'section'], true)) {
                continue;
            }

            $credited = $prior->get($line->id) ?? collect();
            $remainingQty = round((float) $line->quantity - (float) $credited->sum(fn ($l) => (float) $l->quantity), 4);

            if ($remainingQty <= 0.00001) {
                continue;
            }

            $remainingDiscount = max(0.0, round(
                (float) ($line->discount_amount ?? 0) - (float) $credited->sum(fn ($l) => (float) ($l->discount_amount ?? 0)),
                2,
            ));

            $lines[] = [
                'invoice_line_id' => $line->id,
                'product_id' => $line->product_id,
                'description' => (string) ($line->description ?: 'Invoice line '.$line->id),
                'quantity' => $remainingQty,
                'unit_price' => (float) $line->unit_price,
                'tax_inclusive' => (bool) $line->tax_inclusive,
                'discount_amount' => $remainingDiscount,
                'account_id' => $line->gl_account_id,
            ];
        }

        return $lines;
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
