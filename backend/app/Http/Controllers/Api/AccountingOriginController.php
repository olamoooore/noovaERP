<?php

namespace App\Http\Controllers\Api;

use App\Accounting\DTOs\AccountingDocumentLineData;
use App\Accounting\DTOs\AccountingOriginBillData;
use App\Accounting\DTOs\AccountingOriginInvoiceData;
use App\Accounting\DTOs\PostInvoiceRequest;
use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Invoice;
use App\Services\AccountingOriginBillService;
use App\Services\AccountingOriginInvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Accounting-origin document creation controller.
 *
 * Provides endpoints for accountants / admin users to create AR invoices
 * and AP bills directly, without going through Sales Order or Procurement flows.
 * All documents are stamped with origin_type = 'accounting_origin'.
 */
class AccountingOriginController extends Controller
{
    public function __construct(
        private readonly AccountingOriginInvoiceService $invoiceService,
        private readonly AccountingOriginBillService $billService,
    ) {}

    // ── AR Invoices ─────────────────────────────────────────

    /**
     * Create an accounting-origin invoice.
     */
    public function storeInvoice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id'       => 'required|integer',
            'invoice_date'      => 'nullable|date',
            'due_date'          => 'nullable|date',
            'notes'             => 'nullable|string',
            'terms'             => 'nullable|string',
            'customer_reference' => 'nullable|string|max:100',
            'internal_reference' => 'nullable|string|max:100',
            'discount_amount'   => 'nullable|numeric|min:0',
            'auto_post'         => 'nullable|boolean',
            'lines'             => 'required|array|min:1',
            'lines.*.line_type' => 'required|string|in:product,service,expense,tax,discount',
            'lines.*.description' => 'required|string',
            'lines.*.quantity'  => 'required|numeric|min:0.01',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.product_id' => 'nullable|integer|exists:products,id',
            'lines.*.gl_account_id' => 'nullable|integer|exists:chart_of_accounts,id',
            'lines.*.tax_rate'  => 'nullable|numeric|min:0|max:100',
            'lines.*.discount_amount' => 'nullable|numeric|min:0',
            'lines.*.cost_center_id' => 'nullable|integer',
            'lines.*.project_id' => 'nullable|integer',
            'lines.*.department_id' => 'nullable|integer',
            'lines.*.branch_id' => 'nullable|integer',
        ]);

        $lines = array_map(fn ($l) => new AccountingDocumentLineData(
            lineType: $l['line_type'],
            description: $l['description'],
            quantity: $l['quantity'],
            unitPrice: $l['unit_price'],
            productId: $l['product_id'] ?? null,
            glAccountId: $l['gl_account_id'] ?? null,
            taxRate: $l['tax_rate'] ?? 0,
            discountAmount: $l['discount_amount'] ?? 0,
            costCenterId: $l['cost_center_id'] ?? null,
            projectId: $l['project_id'] ?? null,
            departmentId: $l['department_id'] ?? null,
            branchId: $l['branch_id'] ?? null,
        ), $validated['lines']);

        $data = new AccountingOriginInvoiceData(
            customerId: $validated['customer_id'],
            lines: $lines,
            invoiceDate: $validated['invoice_date'] ?? null,
            dueDate: $validated['due_date'] ?? null,
            notes: $validated['notes'] ?? null,
            terms: $validated['terms'] ?? null,
            customerReference: $validated['customer_reference'] ?? null,
            internalReference: $validated['internal_reference'] ?? null,
            discountAmount: $validated['discount_amount'] ?? 0,
            autoPost: $validated['auto_post'] ?? false,
        );

        $invoice = $this->invoiceService->create($data);

        return response()->json($invoice, 201);
    }

    /**
     * Post an accounting-origin invoice.
     */
    public function postInvoice(int $id): JsonResponse
    {
        $invoice = $this->invoiceService->post(new PostInvoiceRequest(invoiceId: $id));

        return response()->json([
            'message' => 'Invoice posted successfully',
            'invoice' => $invoice,
        ]);
    }

    // ── AP Bills ────────────────────────────────────────────

    /**
     * Create an accounting-origin bill.
     */
    public function storeBill(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor_id'         => 'required|integer',
            'bill_type'         => 'required|string|in:expense,procurement',
            'bill_date'         => 'nullable|date',
            'due_date'          => 'nullable|date',
            'notes'             => 'nullable|string',
            'terms'             => 'nullable|string',
            'vendor_reference'  => 'nullable|string|max:100',
            'internal_reference' => 'nullable|string|max:100',
            'discount_amount'   => 'nullable|numeric|min:0',
            'auto_post'         => 'nullable|boolean',
            'lines'             => 'required|array|min:1',
            'lines.*.line_type' => 'required|string|in:product,service,expense,tax,discount',
            'lines.*.description' => 'required|string',
            'lines.*.quantity'  => 'required|numeric|min:0.01',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.product_id' => 'nullable|integer|exists:products,id',
            'lines.*.gl_account_id' => 'nullable|integer|exists:chart_of_accounts,id',
            'lines.*.tax_rate'  => 'nullable|numeric|min:0|max:100',
            'lines.*.discount_amount' => 'nullable|numeric|min:0',
            'lines.*.cost_center_id' => 'nullable|integer',
            'lines.*.project_id' => 'nullable|integer',
            'lines.*.department_id' => 'nullable|integer',
            'lines.*.branch_id' => 'nullable|integer',
        ]);

        $lines = array_map(fn ($l) => new AccountingDocumentLineData(
            lineType: $l['line_type'],
            description: $l['description'],
            quantity: $l['quantity'],
            unitPrice: $l['unit_price'],
            productId: $l['product_id'] ?? null,
            glAccountId: $l['gl_account_id'] ?? null,
            taxRate: $l['tax_rate'] ?? 0,
            discountAmount: $l['discount_amount'] ?? 0,
            costCenterId: $l['cost_center_id'] ?? null,
            projectId: $l['project_id'] ?? null,
            departmentId: $l['department_id'] ?? null,
            branchId: $l['branch_id'] ?? null,
        ), $validated['lines']);

        $data = new AccountingOriginBillData(
            vendorId: $validated['vendor_id'],
            billType: $validated['bill_type'],
            lines: $lines,
            billDate: $validated['bill_date'] ?? null,
            dueDate: $validated['due_date'] ?? null,
            notes: $validated['notes'] ?? null,
            terms: $validated['terms'] ?? null,
            vendorReference: $validated['vendor_reference'] ?? null,
            internalReference: $validated['internal_reference'] ?? null,
            discountAmount: $validated['discount_amount'] ?? 0,
            autoPost: $validated['auto_post'] ?? false,
        );

        $bill = $this->billService->create($data);

        return response()->json($bill, 201);
    }

    /**
     * Post an accounting-origin bill.
     */
    public function postBill(int $id): JsonResponse
    {
        $bill = Bill::findOrFail($id);
        $bill = $this->billService->postBill($bill);

        return response()->json([
            'message' => 'Bill posted successfully',
            'bill'    => $bill,
        ]);
    }
}
