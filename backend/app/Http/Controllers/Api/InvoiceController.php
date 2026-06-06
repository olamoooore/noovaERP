<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Modules\Sales\Services\SalesOrderService;

class InvoiceController extends Controller
{
    public function __construct(
        private InvoiceService $invoiceService,
        private SalesOrderService $salesOrderService,
    ) {}

    public function index(Request $request)
    {
        $query = Invoice::with(['customer', 'lines'])
            ->orderBy('invoice_date', 'desc');

        if ($request->has('status')) {
            $statuses = explode(',', $request->status);
            $query->whereIn('status', $statuses);
        }

        if ($request->has('lifecycle_status')) {
            $lifecycleStatuses = explode(',', $request->lifecycle_status);
            $query->whereIn('lifecycle_status', $lifecycleStatuses);
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'ILIKE', "%{$search}%")
                  ->orWhereHas('customer', function ($cq) use ($search) {
                      $cq->where('name', 'ILIKE', "%{$search}%");
                  });
            });
        }

        $invoices = $query->paginate($request->per_page ?? 20);

        return response()->json($invoices);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'invoice_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'customer_reference' => 'nullable|string|max:100',
            'internal_reference' => 'nullable|string|max:100',
            'attention_to' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'billing_address_snapshot' => 'nullable|string',
            'shipping_address_snapshot' => 'nullable|string',
            'company_registration_number' => 'nullable|string|max:100',
            'tax_registration_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'terms' => 'nullable|string',
            'discount_amount' => 'nullable|numeric|min:0',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'nullable|exists:products,id',
            'lines.*.description' => 'required|string',
            'lines.*.quantity' => 'required|integer|min:1',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'lines.*.discount_amount' => 'nullable|numeric|min:0',
        ]);

        try {
            // Map invoice lines to SalesOrder line format
            $salesLines = array_map(fn ($line) => [
                'product_id'      => $line['product_id'] ?? null,
                'description'     => $line['description'],
                'quantity'        => $line['quantity'],
                'unit_price'      => $line['unit_price'],
                'tax_rate'        => $line['tax_rate'] ?? 0,
                'discount_amount' => $line['discount_amount'] ?? 0,
            ], $validated['lines']);

            // Resolve warehouse for stock-tracked products
            $warehouseId = null;
            $productIds = array_filter(array_column($salesLines, 'product_id'));
            if (!empty($productIds)) {
                $hasStockTracked = Product::whereIn('id', $productIds)
                    ->where('track_inventory', true)
                    ->exists();
                if ($hasStockTracked) {
                    $warehouse = Warehouse::getDefault() ?? Warehouse::first();
                    if (!$warehouse) {
                        return response()->json([
                            'message' => 'Cannot create invoice for stock-tracked products: no warehouse configured. Please set up a warehouse first.',
                        ], 422);
                    }
                    $warehouseId = $warehouse->id;
                }
            }

            // Create SalesOrder on the admin channel so every manual invoice
            // is tracked by the SalesOrder backbone and has a SalesInvoiceLink.
            $salesOrder = $this->salesOrderService->createOrder([
                'customer_id' => $validated['customer_id'],
                'channel'     => 'admin',
                'notes'       => $validated['notes'] ?? null,
                'lines'       => $salesLines,
                'warehouse_id'=> $warehouseId,
            ]);

            // Fast-track admin orders through the full lifecycle immediately
            $salesOrder = $this->salesOrderService->confirmOrder($salesOrder);
            $salesOrder = $this->salesOrderService->allocateOrder($salesOrder);
            $salesOrder = $this->salesOrderService->fulfilOrder($salesOrder);

            // Collect invoice-specific fields that are not on SalesOrder lines
            $invoiceOverrides = array_filter([
                'invoice_date'                => $validated['invoice_date'] ?? null,
                'due_date'                    => $validated['due_date'] ?? null,
                'customer_reference'          => $validated['customer_reference'] ?? null,
                'internal_reference'          => $validated['internal_reference'] ?? null,
                'attention_to'                => $validated['attention_to'] ?? null,
                'contact_email'               => $validated['contact_email'] ?? null,
                'contact_phone'               => $validated['contact_phone'] ?? null,
                'billing_address_snapshot'    => $validated['billing_address_snapshot'] ?? null,
                'shipping_address_snapshot'   => $validated['shipping_address_snapshot'] ?? null,
                'company_registration_number' => $validated['company_registration_number'] ?? null,
                'tax_registration_number'     => $validated['tax_registration_number'] ?? null,
                'notes'                       => $validated['notes'] ?? null,
                'terms'                       => $validated['terms'] ?? null,
                'discount_amount'             => $validated['discount_amount'] ?? null,
            ], fn ($v) => $v !== null);

            $invoice = $this->salesOrderService->createInvoice(
                $salesOrder,
                empty($invoiceOverrides) ? null : $invoiceOverrides
            );

            return response()->json([
                'message' => 'Invoice created successfully',
                'id' => $invoice->id,
                'data' => $invoice,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create invoice',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        $invoice = Invoice::with(['customer', 'lines.product', 'journalEntry', 'payments'])->find($id);

        if (!$invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        return response()->json($invoice);
    }

    public function update(Request $request, $id)
    {
        $invoice = Invoice::find($id);

        if (!$invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        if ($invoice->status !== 'draft') {
            return response()->json(['message' => 'Only draft invoices can be edited'], 403);
        }

        $validated = $request->validate([
            'customer_id' => 'exists:customers,id',
            'invoice_date' => 'date',
            'due_date' => 'date',
            'customer_reference' => 'nullable|string|max:100',
            'internal_reference' => 'nullable|string|max:100',
            'attention_to' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'billing_address_snapshot' => 'nullable|string',
            'shipping_address_snapshot' => 'nullable|string',
            'company_registration_number' => 'nullable|string|max:100',
            'tax_registration_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'terms' => 'nullable|string',
            'discount_amount' => 'nullable|numeric|min:0',
        ]);

        $invoice->update($validated);
        $invoice->calculateTotals();

        return response()->json($invoice->fresh(['customer', 'lines']));
    }

    public function send($id)
    {
        $invoice = Invoice::find($id);

        if (!$invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        // Idempotent guard: already-posted invoices return success without re-posting
        if ($invoice->status === 'posted') {
            return response()->json([
                'message' => 'Invoice already posted',
                'invoice' => $invoice->fresh(['journalEntry', 'lines']),
            ]);
        }

        try {
            $posted = $this->invoiceService->postInvoice($invoice);

            return response()->json([
                'message' => 'Invoice posted successfully',
                'invoice' => $posted,
            ]);

        } catch (\RuntimeException $e) {
            $message = $e->getMessage();

            // Surface account-mapping errors with actionable guidance
            if (str_contains($message, 'Cannot resolve GL account')) {
                $message = 'Default accounts not configured. ' .
                    'Please go to Company Settings > Default Accounts and map the required GL accounts before posting invoices.';
            }

            \Log::warning('Invoice posting failed', [
                'invoice_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to post invoice',
                'error' => $message,
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Invoice posting failed unexpectedly', [
                'invoice_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to post invoice',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $invoice = Invoice::find($id);

        if (!$invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        if ($invoice->status !== 'draft') {
            return response()->json(['message' => 'Only draft invoices can be deleted'], 403);
        }

        $invoice->delete();

        return response()->json(['message' => 'Invoice deleted successfully']);
    }
}
