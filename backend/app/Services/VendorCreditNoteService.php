<?php

namespace App\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\VendorCreditNote;
use App\Models\VendorCreditNoteLine;
use Illuminate\Support\Facades\DB;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

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

            $data['credit_note_date'] = $data['credit_note_date'] ?? now();
            $data['status'] = 'draft';
            $data['currency_code'] = $data['currency_code'] ?? 'USD';

            // Calculate totals from lines BEFORE inserting (columns are NOT NULL)
            $subtotal = 0;
            $taxAmount = 0;
            foreach ($lines as $lineData) {
                $lineTotal = (float) $lineData['quantity'] * (float) $lineData['unit_price'];
                $subtotal += $lineTotal;
                $taxAmount += $lineTotal * ((float) ($lineData['tax_rate'] ?? 0) / 100);
            }
            $data['subtotal'] = round($subtotal, 2);
            $data['tax_amount'] = round($taxAmount, 2);
            $data['total_amount'] = round($subtotal + $taxAmount, 2);

            $data['created_by'] = TenantUserHelper::resolveTenantUserId();

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

            DB::commit();
            
            return $creditNote->load(['vendor', 'bill', 'lines.product']);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Post credit note (creates journal entry).
     *
     * A vendor credit note REDUCES what we owe the vendor and REDUCES the
     * expense/tax we originally recorded:
     *
     *   DR  Accounts Payable     (total)  — reduce the liability
     *   CR  Input Tax            (tax)    — reverse the recoverable tax
     *   CR  Expense Account      (subtotal) — reverse the original expense
     *
     * Hardening (2026-04-15):
     *   - Corrected journal direction (was DR Expense / CR AP — backwards)
     *   - Changed tax key from 'sales_tax_payable' to 'input_tax' (purchase side)
     *   - Replaced non-atomic bill balance update with atomic DB::where()->decrement()
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

            // DR Accounts Payable (total) — reduce the liability we owe the vendor
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'accounts_payable',
                accountId: null,
                debit: (float) $creditNote->total_amount,
                credit: 0,
                description: "Credit Note {$creditNote->credit_note_number}",
            );

            // CR Input Tax — reverse the recoverable tax on the original purchase
            if ((float) $creditNote->tax_amount > 0) {
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'input_tax',
                    accountId: null,
                    debit: 0,
                    credit: (float) $creditNote->tax_amount,
                    description: "Tax reversal - Credit Note {$creditNote->credit_note_number}",
                );
            }

            // CR Expense Account (subtotal) — reverse the original expense
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'general_expense',
                accountId: null,
                debit: 0,
                credit: (float) $creditNote->subtotal,
                description: "Expense reversal - Credit Note {$creditNote->credit_note_number}",
            );

            $result = $this->postingEngine->post(new PostingRequest(
                documentType: 'vendor_credit_note',
                documentId: $creditNote->id,
                postingDate: $creditNote->credit_note_date->toDateString(),
                description: "Vendor Credit Note #{$creditNote->credit_note_number} - {$creditNote->vendor->name}",
                lines: $lines,
                reference: $creditNote->credit_note_number,
                userId: TenantUserHelper::resolveTenantUserId(),
            ));

            if (!$result->success) {
                throw new \Exception($result->error);
            }
            
            $creditNote->update([
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => TenantUserHelper::requireTenantUserId(),
                'journal_entry_id' => $result->journalEntryId,
            ]);

            // Atomically reduce bill balance
            \App\Models\Bill::where('id', $bill->id)
                ->decrement('balance', (float) $creditNote->total_amount);

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
