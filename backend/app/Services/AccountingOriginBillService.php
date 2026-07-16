<?php

namespace App\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\AccountingOriginBillData;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\Bill;
use App\Models\BillLine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

/**
 * Creates and optionally posts bills via the accounting-origin path.
 *
 * This is the dedicated entry point for accountants / admin users who need
 * to create AP documents directly (without a Purchase Order or procurement flow).
 * All bills created here are stamped with origin_type = 'accounting_origin'.
 */
class AccountingOriginBillService
{
    public function __construct(
        private readonly AccountingDocumentPolicyService $policyService,
        private readonly PostingEngineInterface $postingEngine,
    ) {}

    /**
     * Create a bill with origin tracking and optional auto-post.
     */
    public function create(AccountingOriginBillData $data): Bill
    {
        $this->policyService->validateBill($data);

        return DB::transaction(function () use ($data) {
            $actorId = TenantUserHelper::requireTenantUserId(Auth::id());

            $bill = Bill::create([
                'vendor_id' => $data->vendorId,
                'bill_type' => $data->billType,
                'bill_date' => $data->billDate ?? now()->toDateString(),
                'due_date' => $data->dueDate,
                'notes' => $data->notes,
                'terms' => $data->terms,
                'vendor_reference' => $data->vendorReference,
                'internal_reference' => $data->internalReference,
                'discount_amount' => $data->discountAmount,
                'status' => 'draft',
                'subtotal' => 0,
                'tax_amount' => 0,
                'total' => 0,
                'paid_amount' => 0,
                'balance' => 0,
                'created_by' => $actorId,
                'origin_type' => 'accounting_origin',
                'origin_context' => $data->originContext ?: null,
                'created_via_module' => 'accounting',
                'created_via_role' => $data->createdViaRole,
            ]);

            foreach ($data->lines as $line) {
                $quantity = (float) $line->quantity;
                $unitPrice = (float) $line->unitPrice;
                $taxRate = (float) $line->taxRate;
                $lineTotal = $quantity * $unitPrice;
                $taxAmount = $lineTotal * ($taxRate / 100);

                BillLine::create([
                    'bill_id' => $bill->id,
                    'line_type' => $line->lineType,
                    'product_id' => $line->productId,
                    'account_id' => $line->glAccountId,
                    'description' => $line->description,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'line_total' => $lineTotal,
                    'cost_center_id' => $line->costCenterId,
                    'project_id' => $line->projectId,
                    'department_id' => $line->departmentId,
                    'branch_id' => $line->branchId,
                ]);
            }

            // Calculate totals
            $bill->load('lines');
            $bill->subtotal = $bill->lines->sum('line_total');
            $bill->tax_amount = $bill->lines->sum('tax_amount');
            $bill->total = $bill->subtotal + $bill->tax_amount - $bill->discount_amount;
            $bill->balance = $bill->total - $bill->paid_amount;
            $bill->save();

            // Auto-post if requested
            if ($data->autoPost) {
                $this->postBill($bill);
            }

            return $bill->load(['vendor', 'lines.account']);
        });
    }

    /**
     * Post a draft bill to the GL.
     */
    public function postBill(Bill $bill): Bill
    {
        if ($bill->status !== 'draft') {
            throw new \RuntimeException('Only draft bills can be posted.');
        }

        $bill->load('lines');

        $postingLines = [];

        // Per-line expense accounts (DR)
        foreach ($bill->lines as $line) {
            if ((float) $line->line_total > 0) {
                $postingLines[] = new PostingRequestLine(
                    accountMappingKey: 'expense',
                    accountId: $line->account_id,
                    debit: (float) $line->line_total,
                    credit: 0,
                    description: $line->description ?? "Bill {$bill->bill_number}",
                    departmentId: $line->department_id,
                    projectId: $line->project_id,
                );
            }
        }

        // Tax payable (DR) if any
        if ((float) $bill->tax_amount > 0) {
            $postingLines[] = new PostingRequestLine(
                accountMappingKey: 'purchase_tax_receivable',
                accountId: null,
                debit: (float) $bill->tax_amount,
                credit: 0,
                description: "Tax - Bill {$bill->bill_number}",
            );
        }

        // CR Accounts Payable (total)
        $postingLines[] = new PostingRequestLine(
            accountMappingKey: 'accounts_payable',
            accountId: null,
            debit: 0,
            credit: (float) $bill->total,
            description: "Bill {$bill->bill_number}",
        );

        $result = $this->postingEngine->post(new PostingRequest(
            documentType: 'bill',
            documentId: $bill->id,
            postingDate: $bill->bill_date->toDateString(),
            description: "Bill #{$bill->bill_number} - {$bill->vendor->name}",
            lines: $postingLines,
            reference: $bill->bill_number,
            userId: TenantUserHelper::requireTenantUserId($bill->created_by),
        ));

        if (! $result->success) {
            throw new \RuntimeException("Bill posting failed: {$result->error}");
        }

        $bill->update([
            'status' => 'posted',
            'journal_entry_id' => $result->journalEntryId,
        ]);

        return $bill->fresh(['vendor', 'lines.account', 'journalEntry']);
    }
}
