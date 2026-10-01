<?php

namespace App\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\DTOs\PostingResult;
use App\Models\Bill;
use Illuminate\Support\Facades\DB;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class VendorBillService
{
    public function __construct(
        private readonly PostingEngineInterface $postingEngine,
        private readonly TaxDeterminationService $taxDeterminationService,
    ) {}

    /**
     * Post a vendor bill to the GL.
     *
     * DR  Expense / Inventory  (per line account)
     * DR  GRNI                 (issue #105: matched receipt value, if any)
     * DR  Input Tax            (if tax > 0)
     * CR  Accounts Payable     (total)
     *
     * Hardening (2026-04-15): Wrapped entire operation in a DB transaction so
     * the PostingEngine journal entry and the bill status update are atomic.
     * Previously, the bill status update happened outside the posting transaction
     * and could diverge if it failed.
     *
     * Issue #105: when the bill is linked to a confirmed goods receipt, the
     * receipt value (accepted qty × PO unit cost) is posted to GRNI rather than
     * expensed again — the receipt already debited Inventory / credited GRNI.
     */
    public function postBill(Bill $bill): PostingResult
    {
        if ($bill->journal_entry_id || $bill->status !== 'draft') {
            return PostingResult::failure('Bill is already posted.');
        }

        // Validate all bill lines have an explicit GL account before attempting to post.
        // Without this guard a null account_id falls through to the 'general_expense'
        // mapping key which resolves to the SUSPENSE account — silently wrong.
        foreach ($bill->lines as $billLine) {
            if (in_array($billLine->line_type, ['note', 'section'], true)) {
                continue; // Free-text lines carry no posting.
            }
            if (! $billLine->account_id) {
                return PostingResult::failure(
                    "Bill line '{$billLine->description}' (line #{$billLine->id}) has no GL account assigned. ".
                    'All bill lines must have an account selected before the bill can be posted.'
                );
            }
        }

        return DB::transaction(function () use ($bill) {
            $bill->loadMissing('vendor.addresses', 'lines.product');
            $taxDetermination = $this->taxDeterminationService->determineForBill($bill);
            $expenseLines = [];
            $lineTotalSum = 0.0;
            $bookedTaxTotal = 0.0;

            foreach ($bill->lines as $billLine) {
                if (in_array($billLine->line_type, ['note', 'section'], true)) {
                    continue;
                }
                $lineTotal = (float) $billLine->line_total;
                $lineTotalSum += $lineTotal;
                $bookedTaxTotal += (float) $billLine->tax_amount;

                $expenseLines[] = new PostingRequestLine(
                    accountMappingKey: 'general_expense',
                    accountId: $billLine->account_id, // explicit account from bill line (guaranteed non-null above)
                    debit: $lineTotal,
                    credit: 0,
                    description: $billLine->description ?? "Bill {$bill->bill_number}",
                );
            }

            // MRD TAX-REQ-07 (D4/#42 follow-up): one DR input-tax GL line per
            // determined tax_code so every tax-bearing line writes a
            // tax_transaction_details row, while the booked bill tax total is
            // preserved exactly (balance-safe allocation).
            $taxLines = \App\Support\TaxPostingLines::fromPurchases(
                TaxDeterminationService::allocateBookedTax($taxDetermination['tax_lines'] ?? [], round($bookedTaxTotal, 2)),
                "Bill {$bill->bill_number}",
            );

            $netBeforeTax = round($lineTotalSum - (float) ($bill->discount_amount ?? 0), 2);
            $reliefAmount = $this->resolveGrniReliefAmount($bill, $netBeforeTax);
            [$reliefApplied, $expenseLines] = $this->applyGrniRelief($expenseLines, $reliefAmount);

            $lines = array_merge($expenseLines, $taxLines);

            if ($reliefApplied > 0) {
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'grni',
                    accountId: null,
                    debit: $reliefApplied,
                    credit: 0,
                    description: "GRNI relief - Bill {$bill->bill_number}",
                );
            }

            // CR accounts payable for the total
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'accounts_payable',
                accountId: null,
                debit: 0,
                credit: (float) $bill->total,
                description: "AP - Bill {$bill->bill_number}",
            );

            // CR purchase discount if applicable
            $discountAmount = (float) ($bill->discount_amount ?? 0);
            if ($discountAmount > 0) {
                // Use the first line's account as the discount offset account
                $discountAccountId = $bill->lines->first()?->account_id;
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'general_expense',
                    accountId: $discountAccountId,
                    debit: 0,
                    credit: $discountAmount,
                    description: "Discount - Bill {$bill->bill_number}",
                );
            }

            $request = new PostingRequest(
                documentType: 'bill',
                documentId: $bill->id,
                postingDate: $bill->bill_date->toDateString(),
                description: "Vendor Bill #{$bill->bill_number} - {$bill->vendor->name}",
                lines: $lines,
                reference: $bill->bill_number,
                userId: TenantUserHelper::resolveTenantUserId(),
                metadata: [
                    'grni_relief' => $reliefApplied,
                    'goods_receipt_id' => $bill->goods_receipt_id,
                ],
                taxDeterminationResult: $taxDetermination,
            );

            $result = $this->postingEngine->post($request);

            if ($result->success) {
                $bill->update([
                    'status' => 'posted',
                    'journal_entry_id' => $result->journalEntryId,
                ]);
            }

            return $result;
        });
    }

    /**
     * Issue #105: the goods-received-not-invoiced liability this bill relieves.
     * Valued at the linked confirmed GRN's accepted quantity × PO unit cost,
     * capped at the bill's pre-tax net. Zero when the bill has no GRN link.
     */
    private function resolveGrniReliefAmount(Bill $bill, float $netBeforeTax): float
    {
        if ($netBeforeTax <= 0 || ! $bill->goods_receipt_id) {
            return 0.0;
        }

        $grn = \App\Models\GoodsReceipt::with('lines.purchaseOrderItem')->find($bill->goods_receipt_id);

        if (! $grn || $grn->status !== 'confirmed') {
            return 0.0;
        }

        $receiptValue = 0.0;

        foreach ($grn->lines as $line) {
            // Issue #211: fractional accepted quantities must not be truncated
            // (companion to the GoodsReceiptService posting fix #210).
            $receiptValue += (float) $line->quantity_accepted * (float) ($line->purchaseOrderItem?->unit_cost ?? 0);
        }

        // Issue #209: subtract relief already applied by prior posted bills for
        // this GRN, otherwise partial bills over-relieve GRNI (leaving it with
        // a stray negative balance and under-expensing the excess).
        $alreadyRelieved = $this->postedGrniReliefForReceipt((int) $grn->id, (int) $bill->id);

        $remainingReceiptValue = max($receiptValue - $alreadyRelieved, 0.0);

        return round(min($remainingReceiptValue, $netBeforeTax), 2);
    }

    /**
     * Issue #209: GRNI value already relieved by prior posted bills against the
     * same goods receipt. Each bill's relief was min(remaining receipt value,
     * its pre-tax net), so the sum of prior pre-tax nets is equivalent (and the
     * caller clamps the remaining value at zero).
     */
    private function postedGrniReliefForReceipt(int $goodsReceiptId, int $excludeBillId): float
    {
        return (float) Bill::query()
            ->where('goods_receipt_id', $goodsReceiptId)
            ->whereKeyNot($excludeBillId)
            ->whereNotNull('journal_entry_id')
            ->whereNotIn('lifecycle_status', ['voided', 'reversed', 'superseded'])
            ->get()
            ->sum(fn (Bill $prior) => max((float) $prior->subtotal - (float) ($prior->discount_amount ?? 0), 0.0));
    }

    /**
     * Reduce the supplied debit lines by $relief (in order) and return
     * [appliedAmount, reducedLines]. Only expense-key lines are reduced; all
     * other lines pass through untouched.
     *
     * @param  PostingRequestLine[]  $lines
     * @return array{0: float, 1: PostingRequestLine[]}
     */
    private function applyGrniRelief(array $lines, float $relief): array
    {
        if ($relief <= 0) {
            return [0.0, $lines];
        }

        $remaining = $relief;
        $reduced = [];

        foreach ($lines as $line) {
            $isExpenseLine = in_array($line->accountMappingKey, ['general_expense', 'expense'], true);

            if ($remaining > 0 && $isExpenseLine && $line->debit > 0) {
                $applied = min($remaining, $line->debit);
                $remaining = round($remaining - $applied, 2);
                $newDebit = round($line->debit - $applied, 2);

                if ($newDebit <= 0) {
                    continue;
                }

                $reduced[] = new PostingRequestLine(
                    accountMappingKey: $line->accountMappingKey,
                    accountId: $line->accountId,
                    debit: $newDebit,
                    credit: $line->credit,
                    description: $line->description,
                    reference: $line->reference,
                    departmentId: $line->departmentId,
                    projectId: $line->projectId,
                    locationId: $line->locationId,
                    currencyCode: $line->currencyCode,
                    originalAmount: $line->originalAmount,
                    exchangeRate: $line->exchangeRate,
                    taxCode: $line->taxCode,
                    taxId: $line->taxId,
                );

                continue;
            }

            $reduced[] = $line;
        }

        return [round($relief - $remaining, 2), $reduced];
    }
}
