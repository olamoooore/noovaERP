<?php

namespace App\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\VendorCreditNote;
use App\Models\VendorCreditNoteLine;
use Illuminate\Support\Facades\DB;

/**
 * Vendor Credit Note Service
 * Handles credit note workflow and GL posting
 */
class VendorCreditNoteService
{
    public function __construct(
        private PostingEngineInterface $postingEngine,
    ) {}

    /**
     * Create credit note with lines
     */
    public function createCreditNote(array $data): VendorCreditNote
    {
        DB::beginTransaction();
        
        try {
            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $data['created_by'] = auth()->id();
            $data['credit_note_date'] = $data['credit_note_date'] ?? now();
            $data['status'] = 'draft';
            $data['currency_code'] = $data['currency_code'] ?? 'USD';

            $creditNote = VendorCreditNote::create($data);

            // Add lines
            foreach ($lines as $lineData) {
                $creditNote->lines()->create([
                    'bill_line_id' => $lineData['bill_line_id'] ?? null,
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
            
            return $creditNote->load(['vendor', 'bill', 'lines.product']);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Post credit note (creates journal entry)
     * DR: Expense Account
     * CR: Sales Tax Payable
     * CR: Accounts Payable
     */
    public function postCreditNote(VendorCreditNote $creditNote): VendorCreditNote
    {
        if ($creditNote->status !== 'draft') {
            throw new \Exception('Only draft credit notes can be posted');
        }

        // Validate credit note amount doesn't exceed bill amount
        $bill = $creditNote->bill;
        $existingCreditNotes = VendorCreditNote::where('bill_id', $bill->id)
            ->where('status', 'posted')
            ->where('id', '!=', $creditNote->id)
            ->sum('total_amount');
        
        $totalCredited = $existingCreditNotes + $creditNote->total_amount;
        
        if ($totalCredited > $bill->total) {
            throw new \Exception(
                "Credit note amount exceeds bill amount. " .
                "Bill: {$bill->total}, Already credited: {$existingCreditNotes}, " .
                "This credit note: {$creditNote->total_amount}"
            );
        }

        DB::beginTransaction();
        
        try {
            $lines = [];

            // DR Expense (subtotal)
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'general_expense',
                accountId: null,
                debit: (float) $creditNote->subtotal,
                credit: 0,
                description: "Credit Note {$creditNote->credit_note_number}",
            );

            // CR Sales Tax Payable
            if ((float) $creditNote->tax_amount > 0) {
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'sales_tax_payable',
                    accountId: null,
                    debit: 0,
                    credit: (float) $creditNote->tax_amount,
                    description: "Tax - Credit Note {$creditNote->credit_note_number}",
                );
            }

            // CR Accounts Payable (total)
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'accounts_payable',
                accountId: null,
                debit: 0,
                credit: (float) $creditNote->total_amount,
                description: "Credit Note {$creditNote->credit_note_number}",
            );

            $result = $this->postingEngine->post(new PostingRequest(
                documentType: 'vendor_credit_note',
                documentId: $creditNote->id,
                postingDate: $creditNote->credit_note_date->toDateString(),
                description: "Vendor Credit Note #{$creditNote->credit_note_number} - {$creditNote->vendor->name}",
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

            // Update bill balance
            $bill->balance -= $creditNote->total_amount;
            $bill->save();

            DB::commit();
            
            return $creditNote->fresh(['journalEntry', 'lines', 'vendor', 'bill']);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Validate credit note can be created for bill
     */
    public function validateCreditNote(int $billId, float $amount): array
    {
        $bill = \App\Models\Bill::findOrFail($billId);
        
        $existingCreditNotes = VendorCreditNote::where('bill_id', $billId)
            ->where('status', 'posted')
            ->sum('total_amount');
        
        $remainingAmount = $bill->total - $existingCreditNotes;
        
        return [
            'valid' => $amount <= $remainingAmount,
            'bill_amount' => $bill->total,
            'already_credited' => $existingCreditNotes,
            'remaining_amount' => $remainingAmount,
            'requested_amount' => $amount,
        ];
    }
}
