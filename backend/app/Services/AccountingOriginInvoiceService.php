<?php

namespace App\Services;

use App\Accounting\DTOs\AccountingOriginInvoiceData;
use App\Accounting\DTOs\PostInvoiceRequest;
use App\Models\Invoice;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

/**
 * Creates and optionally posts invoices via the accounting-origin path.
 *
 * This is the dedicated entry point for accountants / admin users who need
 * to create AR documents directly (without a Sales Order flow).
 * All invoices created here are stamped with origin_type = 'accounting_origin'.
 *
 * Uses InvoiceService::createInvoiceInternal() via the InvoiceCreationToken
 * pattern, and InvoiceService::postInvoice() for GL posting.
 */
class AccountingOriginInvoiceService
{
    public function __construct(
        private readonly AccountingDocumentPolicyService $policyService,
        private readonly InvoiceService $invoiceService,
    ) {}

    /**
     * Create an accounting-origin invoice with origin tracking and optional auto-post.
     */
    public function create(AccountingOriginInvoiceData $data): Invoice
    {
        $this->policyService->validateInvoice($data);

        return DB::transaction(function () use ($data) {
            $actorId = TenantUserHelper::requireTenantUserId(Auth::id());

            // Build line data with line_type and dimension fields
            $lines = array_map(fn ($line) => array_merge([
                'line_type' => $line->lineType,
                'product_id' => $line->productId,
                'gl_account_id' => $line->glAccountId,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unitPrice,
                'tax_rate' => $line->taxRate,
                'discount_amount' => $line->discountAmount,
                'cost_center_id' => $line->costCenterId,
                'project_id' => $line->projectId,
                'department_id' => $line->departmentId,
                'branch_id' => $line->branchId,
                // Issue #456: preserve the originating sales-order line link.
                'sales_order_line_id' => $line->salesOrderLineId,
                // Issue #331: a down-payment deduction keeps the down
                // payment's tax identity and its link.
                'tax_rule_id' => $line->taxRuleId,
                'tax_code' => $line->taxCode,
                'tax_treatment' => $line->taxTreatment,
                'down_payment_invoice_id' => $line->downPaymentInvoiceId,
            ], $line->taxInclusive !== null ? ['tax_inclusive' => $line->taxInclusive] : []), $data->lines);

            $invoiceData = [
                'customer_id' => $data->customerId,
                'invoice_date' => $data->invoiceDate ?? now()->toDateString(),
                'due_date' => $data->dueDate ?? now()->addDays(30)->toDateString(),
                'notes' => $data->notes,
                'terms' => $data->terms,
                'customer_reference' => $data->customerReference,
                'internal_reference' => $data->internalReference,
                'discount_amount' => $data->discountAmount,
                'created_by' => $actorId,
                'origin_type' => 'accounting_origin',
                'origin_context' => $data->originContext ?: null,
                'created_via_module' => 'accounting',
                'created_via_role' => $data->createdViaRole,
                'lines' => $lines,
            ];

            // Issue #456: carry the source document's currency snapshot and
            // sales-order link; nulls keep the existing resolver behaviour.
            if ($data->currencyCode !== null) {
                $invoiceData['currency_code'] = $data->currencyCode;
            }
            if ($data->baseCurrencyCode !== null) {
                $invoiceData['base_currency_code'] = $data->baseCurrencyCode;
            }
            if ($data->exchangeRate !== null) {
                $invoiceData['exchange_rate'] = $data->exchangeRate;
            }
            if ($data->salesOrderId !== null) {
                $invoiceData['sales_order_id'] = $data->salesOrderId;
            }

            // Use the token-based creation pattern
            $token = $this->invoiceService->issueCreationToken();
            $invoice = $this->invoiceService->createInvoiceInternal($token, $invoiceData);

            // Auto-post if requested
            if ($data->autoPost) {
                $invoice = $this->invoiceService->postInvoice($invoice);
            }

            return $invoice;
        });
    }

    /**
     * Post a draft invoice to the GL.
     */
    public function post(PostInvoiceRequest $request): Invoice
    {
        $invoice = Invoice::findOrFail($request->invoiceId);

        return $this->invoiceService->postInvoice($invoice);
    }
}
