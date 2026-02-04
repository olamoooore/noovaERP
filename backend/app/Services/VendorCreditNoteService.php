<?php

namespace App\Services;

use App\Models\VendorCreditNote;
use App\Models\VendorCreditNoteLine;
use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;

/**
 * Vendor Credit Note Service
 * Handles credit note workflow and GL posting
 */
class VendorCreditNoteService
{
    public function __construct(
        private AccountingService $accountingService
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
            // Get GL accounts
            $apAccount = ChartOfAccount::where('code', '2100')->first(); // A/P
            $expenseAccount = ChartOfAccount::where('code', '5100')->first(); // Expenses
            $taxAccount = ChartOfAccount::where('code', '2200')->first(); // Tax Payable

            if (!$apAccount || !$expenseAccount || !$taxAccount) {
                throw new \Exception('Required GL accounts not found. Please configure chart of accounts.');
            }

            // Create journal entry lines
            $lines = [
                [
                    'account_id' => $expenseAccount->id,
                    'debit' => $creditNote->subtotal,
                    'credit' => 0,
                    'description' => "Credit Note {$creditNote->credit_note_number}",
                ],
            ];

            if ($creditNote->tax_amount > 0) {
                $lines[] = [
                    'account_id' => $taxAccount->id,
                    'debit' => 0,
                    'credit' => $creditNote->tax_amount,
                    'description' => "Tax - Credit Note {$creditNote->credit_note_number}",
                ];
            }

            $lines[] = [
                'account_id' => $apAccount->id,
                'debit' => 0,
                'credit' => $creditNote->total_amount,
                'description' => "Credit Note {$creditNote->credit_note_number}",
            ];

            // Post to accounting ledger
            $journalEntry = $this->accountingService->createJournalEntry([
                'entry_date' => $creditNote->credit_note_date,
                'type' => 'credit_note',
                'reference' => $creditNote->credit_note_number,
                'description' => "Vendor Credit Note #{$creditNote->credit_note_number} - {$creditNote->vendor->name}",
                'status' => 'posted',
            ], $lines);
            
            $creditNote->update([
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => auth()->id(),
                'journal_entry_id' => $journalEntry->id,
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
