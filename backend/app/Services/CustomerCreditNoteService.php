<?php

namespace App\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\DTOs\ReversalRequest;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
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

            $subtotal = 0.0;
            $lineTaxSum = 0.0;

            foreach ($lines as $lineData) {
                $origin = ($lineData['invoice_line_id'] ?? null) ? $sourceLines->get($lineData['invoice_line_id']) : null;

                $calc = \App\Models\InvoiceLine::computeLineTotals(
                    (float) $lineData['quantity'],
                    (float) $lineData['unit_price'],
                    (float) ($lineData['tax_rate'] ?? 0),
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
            foreach ($lines as $lineData) {
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
                    'tax_rate' => $lineData['tax_rate'] ?? 0,
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

        // Validate credit note amount doesn't exceed invoice amount
        $invoice = $creditNote->invoice;
        $existingCreditNotes = CustomerCreditNote::where('invoice_id', $invoice->id)
            ->where('status', 'posted')
            ->where('id', '!=', $creditNote->id)
            ->sum('total_amount');

        $totalCredited = $existingCreditNotes + $creditNote->total_amount;

        if ($totalCredited > $invoice->total) {
            throw new \Exception(
                'Credit note amount exceeds invoice amount. '.
                "Invoice: {$invoice->total}, Already credited: {$existingCreditNotes}, ".
                "This credit note: {$creditNote->total_amount}"
            );
        }

        DB::beginTransaction();

        try {
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
                $rate = (float) ($line->tax_rate ?? 0);
                $origin = $line->invoice_line_id !== null ? ($originalLines[$line->invoice_line_id] ?? null) : null;

                // #512 (CN3): the shared line arithmetic — net base and tax
                // honour the line's discount and tax mode (the old call
                // forced exclusive, undiscounted tax on top).
                $calc = \App\Models\InvoiceLine::computeLineTotals(
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
                    'rate' => $origin && (float) $origin['tax_rate'] > 0 ? (float) $origin['tax_rate'] : $rate,
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
