<?php

namespace App\Services;

use App\Models\CustomerCreditNote;
use App\Models\CustomerCreditNoteLine;
use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;

/**
 * Customer Credit Note Service
 * Handles credit note workflow and GL posting
 */
class CustomerCreditNoteService
{
    public function __construct(
        private AccountingService $accountingService
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
            // Get GL accounts
            $arAccount = ChartOfAccount::where('code', '1100')->first(); // A/R
            $revenueAccount = ChartOfAccount::where('code', '4100')->first(); // Sales
            $taxAccount = ChartOfAccount::where('code', '2200')->first(); // Tax Payable

            if (!$arAccount || !$revenueAccount || !$taxAccount) {
                throw new \Exception('Required GL accounts not found. Please configure chart of accounts.');
            }

            // Create journal entry lines
            $lines = [
                [
                    'account_id' => $revenueAccount->id,
                    'debit' => $creditNote->subtotal,
                    'credit' => 0,
                    'description' => "Credit Note {$creditNote->credit_note_number}",
                ],
            ];

            if ($creditNote->tax_amount > 0) {
                $lines[] = [
                    'account_id' => $taxAccount->id,
                    'debit' => $creditNote->tax_amount,
                    'credit' => 0,
                    'description' => "Tax - Credit Note {$creditNote->credit_note_number}",
                ];
            }

            $lines[] = [
                'account_id' => $arAccount->id,
                'debit' => 0,
                'credit' => $creditNote->total_amount,
                'description' => "Credit Note {$creditNote->credit_note_number}",
            ];

            // Post to accounting ledger
            $journalEntry = $this->accountingService->createJournalEntry([
                'entry_date' => $creditNote->credit_note_date,
                'type' => 'credit_note',
                'reference' => $creditNote->credit_note_number,
                'description' => "Credit Note #{$creditNote->credit_note_number} - {$creditNote->customer->name}",
                'status' => 'posted',
            ], $lines);
            
            $creditNote->update([
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => auth()->id(),
                'journal_entry_id' => $journalEntry->id,
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
