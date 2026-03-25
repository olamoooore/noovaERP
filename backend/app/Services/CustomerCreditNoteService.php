<?php

namespace App\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\CustomerCreditNote;
use App\Models\CustomerCreditNoteLine;
use Illuminate\Support\Facades\DB;

/**
 * Customer Credit Note Service
 * Handles credit note workflow and GL posting
 */
class CustomerCreditNoteService
{
    public function __construct(
        private AccountingService $accountingService,
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

            $data['created_by'] = auth()->id();
            $data['credit_note_date'] = $data['credit_note_date'] ?? now();
            $data['status'] = 'draft';
            $data['currency_code'] = $data['currency_code'] ?? 'USD';

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

            $creditNote->refresh();
            $creditNote->calculateTotals();

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
                "Credit note amount exceeds invoice amount. " .
                "Invoice: {$invoice->total}, Already credited: {$existingCreditNotes}, " .
                "This credit note: {$creditNote->total_amount}"
            );
        }

        DB::beginTransaction();
        
        try {
            $lines = [];

            // DR Sales Revenue (subtotal)
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'sales_revenue',
                accountId: null,
                debit: (float) $creditNote->subtotal,
                credit: 0,
                description: "Credit Note {$creditNote->credit_note_number}",
            );

            // DR Sales Tax Payable
            if ((float) $creditNote->tax_amount > 0) {
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'sales_tax_payable',
                    accountId: null,
                    debit: (float) $creditNote->tax_amount,
                    credit: 0,
                    description: "Tax - Credit Note {$creditNote->credit_note_number}",
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
                userId: auth()->id(),
            ));

            if (!$result->success) {
                throw new \Exception($result->error);
            }
            
            $creditNote->update([
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => auth()->id(),
                'journal_entry_id' => $result->journalEntryId,
            ]);

            // Update invoice balance
            $invoice->balance -= $creditNote->total_amount;
            $invoice->save();

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
