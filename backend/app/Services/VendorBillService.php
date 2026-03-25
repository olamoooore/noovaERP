<?php

namespace App\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\DTOs\PostingResult;
use App\Models\Bill;

class VendorBillService
{
    public function __construct(
        private readonly PostingEngineInterface $postingEngine,
    ) {}

    /**
     * Post a vendor bill to the GL.
     *
     * DR  Expense / Inventory  (per line account)
     * DR  Input Tax            (if tax > 0)
     * CR  Accounts Payable     (total)
     */
    public function postBill(Bill $bill): PostingResult
    {
        if ($bill->status === 'posted') {
            return PostingResult::failure('Bill is already posted.');
        }

        $lines = [];

        // DR each expense/inventory line
        foreach ($bill->lines as $billLine) {
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'general_expense',
                accountId: $billLine->account_id, // explicit account from bill line
                debit: (float) $billLine->line_total,
                credit: 0,
                description: $billLine->description ?? "Bill {$bill->bill_number}",
            );

            // DR input tax per line
            if ((float) $billLine->tax_amount > 0) {
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'input_tax',
                    accountId: null,
                    debit: (float) $billLine->tax_amount,
                    credit: 0,
                    description: "Tax - Bill {$bill->bill_number}",
                );
            }
        }

        // CR accounts payable for the total
        $lines[] = new PostingRequestLine(
            accountMappingKey: 'accounts_payable',
            accountId: null,
            debit: 0,
            credit: (float) $bill->total,
            description: "AP - Bill {$bill->bill_number}",
        );

        $request = new PostingRequest(
            documentType: 'bill',
            documentId: $bill->id,
            postingDate: $bill->bill_date->toDateString(),
            description: "Vendor Bill #{$bill->bill_number} - {$bill->vendor->name}",
            lines: $lines,
            reference: $bill->bill_number,
            userId: auth()->id(),
        );

        $result = $this->postingEngine->post($request);

        if ($result->success) {
            $bill->update([
                'status' => 'posted',
                'journal_entry_id' => $result->journalEntryId,
            ]);
        }

        return $result;
    }
}
