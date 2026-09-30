<?php

namespace App\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\VendorDebitNote;
use Illuminate\Support\Facades\DB;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class VendorDebitNoteService
{
    public function __construct(
        private PostingEngineInterface $postingEngine,
    ) {}

    public function createDebitNote(array $data): VendorDebitNote
    {
        DB::beginTransaction();

        try {
            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $data['debit_note_date'] = $data['debit_note_date'] ?? now();
            $data['status'] = 'draft';
            $data['currency_code'] = $data['currency_code'] ?? 'USD';

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

            $data['created_by'] = TenantUserHelper::resolveTenantUserId();

            $debitNote = VendorDebitNote::create($data);

            foreach ($lines as $lineData) {
                $debitNote->lines()->create([
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

            return $debitNote->load(['vendor', 'bill', 'lines.product']);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function postDebitNote(VendorDebitNote $debitNote): VendorDebitNote
    {
        if ($debitNote->status !== 'draft') {
            throw new \Exception('Only draft debit notes can be posted');
        }

        $bill = $debitNote->bill;
        $existingDebitNotes = VendorDebitNote::where('bill_id', $bill->id)
            ->where('status', 'posted')
            ->where('id', '!=', $debitNote->id)
            ->sum('total_amount');

        $totalDebited = $existingDebitNotes + $debitNote->total_amount;

        if ($totalDebited > $bill->total) {
            throw new \Exception(
                'Debit note amount exceeds bill amount. '.
                "Bill: {$bill->total}, Already debited: {$existingDebitNotes}, ".
                "This debit note: {$debitNote->total_amount}"
            );
        }

        DB::beginTransaction();

        try {
            $lines = [];

            // MRD TAX-REQ-07 (D4 family): reverse the ORIGINAL bill's input-tax
            // identity per line via the persisted bill determination, and write
            // a canonical versioned determination so the debit note lands in
            // tax_transaction_details and nets on the statutory return.
            $determinationService = app(TaxDeterminationService::class);
            $debitNote->loadMissing('lines.product');
            $originalLines = $determinationService->latestDeterminationLines('bill', (int) $debitNote->bill_id);
            $originalResult = \App\Models\TaxDeterminationResult::where('source_record_type', 'bill')
                ->where('source_record_id', (int) $debitNote->bill_id)
                ->current()
                ->orderByDesc('result_version')
                ->first();

            $dnLines = [];
            foreach ($debitNote->lines as $line) {
                $base = round((float) $line->quantity * (float) $line->unit_price, 2);
                $rate = (float) ($line->tax_rate ?? 0);
                $origin = $line->bill_line_id !== null ? ($originalLines[$line->bill_line_id] ?? null) : null;

                $dnLines[] = [
                    'source_line_id' => $line->id,
                    'product' => $line->product,
                    'base' => $base,
                    'rate' => $origin && (float) $origin['tax_rate'] > 0 ? (float) $origin['tax_rate'] : $rate,
                    'tax' => \App\Services\TaxEngineService::calculate($base, $rate, false)['tax'],
                    'tax_code_override' => $origin['tax_code'] ?? null,
                    'treatment_override' => $origin['treatment'] ?? null,
                    'tax_id_override' => $origin['tax_id'] ?? null,
                    'creditable_override' => $origin['creditable'] ?? null,
                ];
            }

            $taxDetermination = null;
            $bookedTaxTotal = round((float) $debitNote->tax_amount, 2);

            if ($bookedTaxTotal > 0 && $dnLines !== []) {
                $taxDetermination = $determinationService->determineForLineSet(
                    documentType: 'vendor_debit_note',
                    documentId: (int) $debitNote->id,
                    date: $debitNote->debit_note_date->toDateString(),
                    lines: $dnLines,
                    direction: 'input',
                    jurisdiction: $originalResult?->jurisdiction,
                    vendorId: $debitNote->vendor_id,
                );
            }

            $lines[] = new PostingRequestLine(
                accountMappingKey: 'accounts_payable',
                accountId: null,
                debit: (float) $debitNote->total_amount,
                credit: 0,
                description: "Debit Note {$debitNote->debit_note_number}",
            );

            foreach (TaxDeterminationService::allocateBookedTax($taxDetermination['tax_lines'] ?? [], $bookedTaxTotal) as $allocation) {
                if ($allocation['amount'] <= 0) {
                    continue;
                }
                // MRD R4.2: reversing a NON-creditable purchase credits the
                // blocked-expense account actually used at posting, never a
                // claim that never existed.
                $blocked = ($allocation['creditable'] ?? true) === false;
                $lines[] = new PostingRequestLine(
                    accountMappingKey: $blocked ? 'input_tax_blocked' : 'input_tax',
                    accountId: $blocked ? ($allocation['blocked_account_id'] ?? null) : null,
                    debit: 0,
                    credit: $allocation['amount'],
                    description: ($blocked ? 'Non-creditable tax reversal - ' : 'Tax reversal - ').($allocation['tax_code'] ? "{$allocation['tax_code']} - " : '')."Tax reversal - Debit Note {$debitNote->debit_note_number}",
                    taxCode: $allocation['tax_code'],
                    taxId: $allocation['tax_id'],
                );
            }

            $lines[] = new PostingRequestLine(
                accountMappingKey: 'general_expense',
                accountId: null,
                debit: 0,
                credit: (float) $debitNote->subtotal,
                description: "Expense reversal - Debit Note {$debitNote->debit_note_number}",
            );

            $result = $this->postingEngine->post(new PostingRequest(
                documentType: 'vendor_debit_note',
                documentId: $debitNote->id,
                postingDate: $debitNote->debit_note_date->toDateString(),
                description: "Vendor Debit Note #{$debitNote->debit_note_number} - {$debitNote->vendor->name}",
                lines: $lines,
                reference: $debitNote->debit_note_number,
                userId: TenantUserHelper::resolveTenantUserId(),
                taxDeterminationResult: $taxDetermination,
            ));

            if (! $result->success) {
                throw new \Exception($result->error);
            }

            $debitNote->update([
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => TenantUserHelper::requireTenantUserId(),
                'journal_entry_id' => $result->journalEntryId,
            ]);

            \App\Models\Bill::where('id', $bill->id)
                ->decrement('balance', (float) $debitNote->total_amount);

            DB::commit();

            return $debitNote->fresh(['journalEntry', 'lines', 'vendor', 'bill']);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function validateDebitNote(int $billId, float $amount): array
    {
        $bill = \App\Models\Bill::findOrFail($billId);

        $existingDebitNotes = VendorDebitNote::where('bill_id', $billId)
            ->where('status', 'posted')
            ->sum('total_amount');

        $remainingAmount = $bill->total - $existingDebitNotes;

        return [
            'valid' => $amount <= $remainingAmount,
            'bill_amount' => $bill->total,
            'already_debited' => $existingDebitNotes,
            'remaining_amount' => $remainingAmount,
            'requested_amount' => $amount,
        ];
    }
}
