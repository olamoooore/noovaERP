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
            $data['currency_code'] = $data['currency_code'] ?? 'USD';

            // Calculate totals from lines BEFORE inserting (columns are NOT NULL)
            $subtotal = 0;
            $taxAmount = 0;
            foreach ($lines as $lineData) {
                $lineTotal = (float) $lineData['quantity'] * (float) $lineData['unit_price'];
                $subtotal += $lineTotal;
                // MRD R3.1: engine-owned arithmetic.
                $taxAmount += \App\Services\TaxEngineService::calculate($lineTotal, (float) ($lineData['tax_rate'] ?? 0), false)['tax'];
            }
            $data['subtotal'] = round($subtotal, 2);
            $data['tax_amount'] = round($taxAmount, 2);
            $data['total_amount'] = round($subtotal + $taxAmount, 2);

            // Let the model's creating callback handle created_by attribution
            if (empty($data['created_by'])) {
                $data['created_by'] = TenantUserHelper::resolveTenantUserId(Auth::id());
            } else {
                $data['created_by'] = TenantUserHelper::requireTenantUserId($data['created_by']);
            }

            $creditNote = CustomerCreditNote::create($data);

            // Add lines
            foreach ($lines as $lineData) {
                $creditNote->lines()->create([
                    'invoice_line_id' => $lineData['invoice_line_id'] ?? null,
                    'product_id' => $lineData['product_id'] ?? null,
                    'description' => $lineData['description'],
                    'quantity' => $lineData['quantity'],
                    'unit_price' => $lineData['unit_price'],
                    'tax_rate' => $lineData['tax_rate'] ?? 0,
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
                $base = round((float) $line->quantity * (float) $line->unit_price, 2);
                $rate = (float) ($line->tax_rate ?? 0);
                $origin = $line->invoice_line_id !== null ? ($originalLines[$line->invoice_line_id] ?? null) : null;

                $cnLines[] = [
                    'source_line_id' => $line->id,
                    'product' => $line->product,
                    'base' => $base,
                    'rate' => $origin && (float) $origin['tax_rate'] > 0 ? (float) $origin['tax_rate'] : $rate,
                    'tax' => \App\Services\TaxEngineService::calculate($base, $rate, false)['tax'],
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

            // DR Sales Revenue (subtotal)
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'sales_revenue',
                accountId: null,
                debit: (float) $creditNote->subtotal,
                credit: 0,
                description: "Credit Note {$creditNote->credit_note_number}",
            );

            // DR Sales Tax Payable — one line per reversed tax_code
            foreach (TaxDeterminationService::allocateBookedTax($taxDetermination['tax_lines'] ?? [], $bookedTaxTotal) as $allocation) {
                if ($allocation['amount'] <= 0) {
                    continue;
                }
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'sales_tax_payable',
                    accountId: null,
                    debit: $allocation['amount'],
                    credit: 0,
                    description: "Tax - Credit Note {$creditNote->credit_note_number}".($allocation['tax_code'] ? " [{$allocation['tax_code']}]" : ''),
                    taxCode: $allocation['tax_code'],
                    taxId: $allocation['tax_id'],
                );
            }

            // CR Accounts Receivable (total)
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'accounts_receivable',
                accountId: null,
                debit: 0,
                credit: (float) $creditNote->total_amount,
                description: "Credit Note {$creditNote->credit_note_number}",
            );

            $result = $this->postingEngine->post(new PostingRequest(
                documentType: 'credit_note',
                documentId: $creditNote->id,
                postingDate: $creditNote->credit_note_date->toDateString(),
                description: "Credit Note #{$creditNote->credit_note_number} - {$creditNote->customer->name}",
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

            // Atomically reduce invoice balance and update status via canonical service.
            app(InvoiceService::class)->applyRefundToInvoice($invoice, (float) $creditNote->total_amount);

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

            // Undo the invoice balance reduction performed at posting time.
            if ($creditNote->invoice) {
                app(InvoiceService::class)->restoreRefundToInvoice(
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
