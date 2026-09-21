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
            if (! $billLine->account_id) {
                return PostingResult::failure(
                    "Bill line '{$billLine->description}' (line #{$billLine->id}) has no GL account assigned. ".
                    'All bill lines must have an account selected before the bill can be posted.'
                );
            }
        }

        return DB::transaction(function () use ($bill) {
            $expenseLines = [];
            $taxLines = [];
            $lineTotalSum = 0.0;

            foreach ($bill->lines as $billLine) {
                $lineTotal = (float) $billLine->line_total;
                $lineTotalSum += $lineTotal;

                $expenseLines[] = new PostingRequestLine(
                    accountMappingKey: 'general_expense',
                    accountId: $billLine->account_id, // explicit account from bill line (guaranteed non-null above)
                    debit: $lineTotal,
                    credit: 0,
                    description: $billLine->description ?? "Bill {$bill->bill_number}",
                );

                // DR input tax per line
                if ((float) $billLine->tax_amount > 0) {
                    $taxLines[] = new PostingRequestLine(
                        accountMappingKey: 'input_tax',
                        accountId: null,
                        debit: (float) $billLine->tax_amount,
                        credit: 0,
                        description: "Tax - Bill {$bill->bill_number}",
                    );
                }
            }

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
            $receiptValue += (int) $line->quantity_accepted * (float) ($line->purchaseOrderItem?->unit_cost ?? 0);
        }

        return round(min($receiptValue, $netBeforeTax), 2);
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
