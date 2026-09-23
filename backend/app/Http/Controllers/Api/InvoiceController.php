<?php

namespace App\Http\Controllers\Api;

use App\Exports\InvoicesExport;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CaseImportService;
use App\Services\InvoiceActivityService;
use App\Services\InvoiceApprovalService;
use App\Services\InvoiceDeliveryService;
use App\Services\InvoiceEvidenceService;
use App\Services\InvoiceService;
use App\Services\LockDateValidationService;
use App\Services\SalesApprovalGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Sales\Services\SalesOrderService;

class InvoiceController extends Controller
{
    public function __construct(
        private InvoiceService $invoiceService,
        private SalesOrderService $salesOrderService,
        private LockDateValidationService $lockDateValidator,
        private CaseImportService $caseImportService,
        private InvoiceActivityService $activityService,
        private InvoiceApprovalService $approvalService,
        private InvoiceDeliveryService $deliveryService,
        private InvoiceEvidenceService $evidenceService,
        private SalesApprovalGate $approvalGate,
    ) {}

    public function index(Request $request)
    {
        $query = $this->filteredInvoiceQuery($request);

        if ($request->boolean('ids_only')) {
            $ids = $query->pluck('id');

            return response()->json(['data' => $ids, 'total' => $ids->count()]);
        }

        $query->with(['customer', 'lines']);

        $invoices = $query->paginate($request->per_page ?? 20);

        return response()->json($invoices);
    }

    public function export(Request $request)
    {
        $validated = $request->validate([
            'columns' => ['required', 'array', 'min:1', 'max:'.count(InvoicesExport::COLUMNS)],
            'columns.*' => ['required', 'string', 'distinct', 'in:'.implode(',', array_keys(InvoicesExport::COLUMNS))],
            'status' => ['nullable', 'string'],
            'lifecycle_status' => ['nullable', 'string'],
            'customer_id' => ['nullable'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'search' => ['nullable', 'string', 'max:255'],
            'document_ids' => ['nullable', 'array', 'max:1000'],
            'document_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $query = $this->filteredInvoiceQuery($request);
        $filename = 'invoices_'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(new InvoicesExport($query, $validated['columns']), $filename);
    }

    private function filteredInvoiceQuery(Request $request)
    {
        $query = Invoice::query()->orderBy('invoice_date', 'desc')->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $statuses = explode(',', $request->status);
            $query->whereIn('status', $statuses);
        }

        if ($request->filled('lifecycle_status')) {
            $lifecycleStatuses = explode(',', $request->lifecycle_status);
            $query->whereIn('lifecycle_status', $lifecycleStatuses);
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('document_ids')) {
            $query->whereIn('id', $request->input('document_ids', []));
        }

        if ($request->filled('start_date')) {
            $query->whereDate('invoice_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('invoice_date', '<=', $request->end_date);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'ILIKE', "%{$search}%")
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('name', 'ILIKE', "%{$search}%")
                            ->orWhere('code', 'ILIKE', "%{$search}%");
                    });
            });
        }

        return $query;
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'invoice_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'case_number' => 'nullable|string|max:100',
            'pr_no' => 'nullable|string|max:100', 'contract_no' => 'nullable|string|max:100',
            'plant_code' => 'nullable|string|max:100', 'company_code' => 'nullable|string|max:100',
            'buyer_contact_id' => 'nullable|exists:core_contacts,id',
            'date_of_case' => 'nullable|date',
            'case_location' => 'nullable|string|max:255',
            'patient_name' => 'nullable|string|max:255',
            'cost_center_id' => 'nullable|string|max:100',
            'delivery_date' => 'nullable|date',
            'customer_reference' => 'nullable|string|max:100',
            'internal_reference' => 'nullable|string|max:100',
            'attention_to' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'billing_address_snapshot' => 'nullable|string',
            'shipping_address_snapshot' => 'nullable|string',
            'company_registration_number' => 'nullable|string|max:100',
            'tax_registration_number' => 'nullable|string|max:100',
            'tax_jurisdiction' => 'nullable|string|max:10',
            'tax_regime' => 'nullable|string|max:30',
            'tax_treatment' => 'nullable|string|max:40',
            'notes' => 'nullable|string',
            'terms' => 'nullable|string',
            'discount_amount' => 'nullable|numeric|min:0',
            'po_number' => 'nullable|string|max:100',
            'doctor_contact_id' => 'nullable|exists:core_contacts,id',
            'requestor_contact_id' => 'nullable|exists:core_contacts,id',
            'doctor_contact_name' => 'nullable|string|max:255',
            'requestor_contact_name' => 'nullable|string|max:255',
            'billing_contact_id' => 'nullable|exists:core_contacts,id',
            'shipping_contact_id' => 'nullable|exists:core_contacts,id',
            'billing_contact_name' => 'nullable|string|max:255',
            'shipping_contact_name' => 'nullable|string|max:255',
            'lines' => 'required|array|min:1',
            'lines.*.line_type' => 'nullable|in:product,note,section',
            'lines.*.product_id' => 'nullable|exists:products,id',
            'lines.*.model_number' => 'nullable|string|max:100',
            'lines.*.description' => 'required|string',
            'lines.*.product_notes' => 'nullable|string',
            'lines.*.lot_number' => 'nullable|string|max:100',
            'lines.*.expiry_date' => 'nullable|date',
            'lines.*.quantity' => 'nullable|integer|min:0',
            'lines.*.unit_price' => 'required_if:lines.*.line_type,product|nullable|numeric|min:0',
            'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'lines.*.discount_amount' => 'nullable|numeric|min:0',
        ]);

        // Lock-date check: block creating invoice dated in a closed period
        $invoiceDate = $validated['invoice_date'] ?? now()->toDateString();
        $lockResult = $this->lockDateValidator->validateTransactionDate('sales', $invoiceDate);
        if ($lockResult['is_locked']) {
            return response()->json([
                'message' => "Cannot create invoice dated in a closed period. {$lockResult['message']}",
                'lock_date' => $lockResult['lock_date'],
                'transaction_date' => $invoiceDate,
            ], 422);
        }

        // Reject document discount exceeding the line subtotal
        $subtotal = array_reduce($validated['lines'], function ($sum, $line) {
            if (in_array($line['line_type'] ?? 'product', ['note', 'section'], true)) {
                return $sum;
            }
            $qty = (int) ($line['quantity'] ?? 0);
            $price = (float) ($line['unit_price'] ?? 0);
            $lineDiscount = (float) ($line['discount_amount'] ?? 0);

            return $sum + ($qty * $price) - $lineDiscount;
        }, 0);
        $docDiscount = (float) ($validated['discount_amount'] ?? 0);
        if ($docDiscount > 0 && $subtotal > 0 && $docDiscount > $subtotal) {
            return response()->json([
                'message' => "Discount amount ({$docDiscount}) exceeds the invoice subtotal ({$subtotal}).",
            ], 422);
        }

        foreach (['doctor', 'requestor', 'billing', 'shipping'] as $contactType) {
            $nameKey = $contactType.'_contact_name';
            $idKey = $contactType.'_contact_id';
            if (! empty($validated[$nameKey])) {
                $validated[$idKey] = $this->resolveOrCreateContact($validated[$nameKey], 'person', 'invoice_form');
            }
            unset($validated[$nameKey]);
        }

        try {
            $invoice = $this->createInvoiceFromData($validated);

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

    /**
     * Shared invoice creation flow used by both store() and import().
     * Creates the backing SalesOrder (admin channel, full lifecycle) + Invoice.
     *
     * $data: customer_id, lines[] (product_id?, description, quantity, unit_price,
     *        tax_rate?, discount_amount?), and optional invoice overrides
     *        (invoice_date, due_date, customer_reference, internal_reference,
     *        attention_to, contact_email, contact_phone, billing_address_snapshot,
     *        shipping_address_snapshot, company_registration_number,
     *        tax_registration_number, notes, terms, discount_amount).
     */
    private function ensureTenantSearchPath(): void
    {
        $schema = tenant()->getSchemaName();
        if ($schema) {
            DB::statement("SET search_path TO \"{$schema}\", public");
        }
    }

    private function createInvoiceFromData(array $data): Invoice
    {
        // Re-apply search_path before any DB operations (SalesOrder lifecycle can reset it)
        $this->ensureTenantSearchPath();

        // Preserve the imported invoice number when provided; update existing if re-importing.
        $importedNumber = isset($data['invoice_number']) ? trim((string) $data['invoice_number']) : '';
        $existingInvoice = null;
        if ($importedNumber !== '') {
            $existingInvoice = Invoice::withTrashed()->where('invoice_number', $importedNumber)->first();
            if ($existingInvoice) {
                // If invoice is already posted, skip update to preserve financial integrity
                if ($existingInvoice->status === 'posted' && $existingInvoice->journal_entry_id) {
                    return $existingInvoice;
                }
                // Clean up existing lines for re-creation
                $existingInvoice->lines()->delete();
            }
        }

        // If updating an existing draft invoice, update header + lines directly
        if ($existingInvoice && $existingInvoice->status === 'draft') {
            $existingInvoice->update(array_filter([
                'customer_id' => $data['customer_id'] ?? null,
                'invoice_date' => $data['invoice_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'case_number' => $data['case_number'] ?? null,
                'pr_no' => $data['pr_no'] ?? null, 'contract_no' => $data['contract_no'] ?? null,
                'plant_code' => $data['plant_code'] ?? null, 'company_code' => $data['company_code'] ?? null,
                'buyer_contact_id' => $data['buyer_contact_id'] ?? null,
                'date_of_case' => $data['date_of_case'] ?? null,
                'case_location' => $data['case_location'] ?? null,
                'patient_name' => $data['patient_name'] ?? null,
                'customer_reference' => $data['customer_reference'] ?? null,
                'internal_reference' => $data['internal_reference'] ?? null,
                'attention_to' => $data['attention_to'] ?? null,
                'contact_email' => $data['contact_email'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'billing_address_snapshot' => $data['billing_address_snapshot'] ?? null,
                'shipping_address_snapshot' => $data['shipping_address_snapshot'] ?? null,
                'doctor_contact_id' => $data['doctor_contact_id'] ?? null,
                'requestor_contact_id' => $data['requestor_contact_id'] ?? null,
                'billing_contact_id' => $data['billing_contact_id'] ?? null,
                'shipping_contact_id' => $data['shipping_contact_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
                'discount_amount' => $data['discount_amount'] ?? null,
                'po_number' => $data['po_number'] ?? null,
                'updated_at' => now(),
            ], fn ($v) => $v !== null));

            // Recreate lines
            foreach ($data['lines'] as $line) {
                $existingInvoice->lines()->create([
                    'line_type' => $line['line_type'] ?? 'product',
                    'sequence' => $line['sequence'] ?? 0,
                    'product_id' => $line['product_id'] ?? null,
                    'model_number' => $line['model_number'] ?? null,
                    'description' => $line['description'],
                    'product_notes' => $line['product_notes'] ?? null,
                    'lot_number' => $line['lot_number'] ?? null,
                    'expiry_date' => $line['expiry_date'] ?? null,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'tax_rate' => $line['tax_rate'] ?? 0,
                    'discount_amount' => $line['discount_amount'] ?? 0,
                ]);
            }

            $existingInvoice->load('lines');
            $existingInvoice->calculateTotals();

            return $existingInvoice;
        }

        // For new invoices, create via SalesOrder lifecycle
        $salesLines = array_map(fn ($line) => [
            'line_type' => $line['line_type'] ?? 'product',
            'sequence' => $line['sequence'] ?? 0,
            'product_id' => $line['product_id'] ?? null,
            'model_number' => $line['model_number'] ?? null,
            'description' => $line['description'],
            'product_notes' => $line['product_notes'] ?? null,
            'quantity' => $line['quantity'],
            'unit_price' => $line['unit_price'],
            'tax_rate' => $line['tax_rate'] ?? 0,
            'discount_amount' => $line['discount_amount'] ?? 0,
        ], $data['lines']);

        // Resolve warehouse if any line references a stock-tracked product
        $warehouseId = null;
        $productIds = array_filter(array_column($salesLines, 'product_id'));
        if (! empty($productIds)) {
            $hasStockTracked = Product::whereIn('id', $productIds)
                ->where('track_inventory', true)
                ->exists();
            if ($hasStockTracked) {
                $warehouse = Warehouse::getDefault() ?? Warehouse::first();
                if (! $warehouse) {
                    throw new \RuntimeException(
                        'Cannot create invoice for stock-tracked products: no warehouse configured.'
                    );
                }
                $warehouseId = $warehouse->id;
            }
        }

        $salesOrder = $this->salesOrderService->createOrder([
            'customer_id' => $data['customer_id'],
            'channel' => 'admin',
            'notes' => $data['notes'] ?? null,
            'lines' => $salesLines,
            'warehouse_id' => $warehouseId,
        ]);

        // Fast-track admin orders through the full lifecycle immediately
        // Re-apply search_path after each DB::transaction() call which can reset connections
        $salesOrder = $this->salesOrderService->confirmOrder($salesOrder);
        $this->ensureTenantSearchPath();
        $salesOrder = $this->salesOrderService->allocateOrder($salesOrder);
        $this->ensureTenantSearchPath();
        $salesOrder = $this->salesOrderService->fulfilOrder($salesOrder);
        $this->ensureTenantSearchPath();

        $invoiceOverrides = array_filter([
            'invoice_number' => $importedNumber !== '' ? $importedNumber : null,
            'invoice_date' => $data['invoice_date'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'case_number' => $data['case_number'] ?? null,
            'pr_no' => $data['pr_no'] ?? null,
            'contract_no' => $data['contract_no'] ?? null,
            'plant_code' => $data['plant_code'] ?? null,
            'company_code' => $data['company_code'] ?? null,
            'buyer_contact_id' => $data['buyer_contact_id'] ?? null,
            'date_of_case' => $data['date_of_case'] ?? null,
            'case_location' => $data['case_location'] ?? null,
            'patient_name' => $data['patient_name'] ?? null,
            'cost_center_id' => $data['cost_center_id'] ?? null,
            'delivery_date' => $data['delivery_date'] ?? null,
            'customer_reference' => $data['customer_reference'] ?? null,
            'internal_reference' => $data['internal_reference'] ?? null,
            'attention_to' => $data['attention_to'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'billing_address_snapshot' => $data['billing_address_snapshot'] ?? null,
            'shipping_address_snapshot' => $data['shipping_address_snapshot'] ?? null,
            'company_registration_number' => $data['company_registration_number'] ?? null,
            'tax_registration_number' => $data['tax_registration_number'] ?? null,
            'notes' => $data['notes'] ?? null,
            'terms' => $data['terms'] ?? null,
            'discount_amount' => $data['discount_amount'] ?? null,
            'po_number' => $data['po_number'] ?? null,
            // Contact IDs are handled separately via UPDATE after invoice creation
            // to bypass FK violations caused by connection recycling during SO lifecycle
        ], fn ($v) => $v !== null);

        // Pass contact IDs through the invoice overrides now that contacts
        // are properly persisted (UTF-8 sanitization in resolveOrCreateContact
        // prevents silent INSERT failures that caused FK violations).
        foreach (['doctor_contact_id', 'requestor_contact_id', 'buyer_contact_id', 'billing_contact_id', 'shipping_contact_id'] as $contactKey) {
            if (($data[$contactKey] ?? null) !== null) {
                $invoiceOverrides[$contactKey] = $data[$contactKey];
            }
        }

        $invoice = $this->salesOrderService->createInvoice(
            $salesOrder,
            empty($invoiceOverrides) ? null : $invoiceOverrides
        );

        // Lot/expiry values belong to the invoice lines rather than sales-order lines.
        $invoice->load('lines');
        foreach ($invoice->lines->values() as $index => $invoiceLine) {
            $sourceLine = $data['lines'][$index] ?? [];
            $invoiceLine->update([
                'lot_number' => $sourceLine['lot_number'] ?? null,
                'expiry_date' => $sourceLine['expiry_date'] ?? null,
            ]);
        }

        return $invoice;
    }

    public function show($id)
    {
        $invoice = Invoice::with(['customer', 'lines.product', 'journalEntry', 'payments', 'billingContact', 'shippingContact', 'doctor', 'requestor', 'buyer', 'salesOrder', 'quotation', 'deliveryOrders'])->find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        // Issue #153: surface the linked DO's signed proof-of-delivery so
        // client AP teams can verify receipt + signature alongside the invoice.
        $invoice->proof_of_delivery = $invoice->proofOfDelivery();

        return response()->json($invoice);
    }

    public function update(Request $request, $id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        if ($invoice->status !== 'draft') {
            return response()->json(['message' => 'Only draft invoices can be edited'], 403);
        }

        $validated = $request->validate([
            'customer_id' => 'exists:customers,id',
            'invoice_date' => 'date',
            'due_date' => 'date',
            'case_number' => 'nullable|string|max:100',
            'pr_no' => 'nullable|string|max:100', 'contract_no' => 'nullable|string|max:100',
            'plant_code' => 'nullable|string|max:100', 'company_code' => 'nullable|string|max:100',
            'buyer_contact_id' => 'nullable|exists:core_contacts,id',
            'date_of_case' => 'nullable|date',
            'case_location' => 'nullable|string|max:255',
            'patient_name' => 'nullable|string|max:255',
            'cost_center_id' => 'nullable|string|max:100',
            'delivery_date' => 'nullable|date',
            'customer_reference' => 'nullable|string|max:100',
            'internal_reference' => 'nullable|string|max:100',
            'attention_to' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'billing_address_snapshot' => 'nullable|string',
            'shipping_address_snapshot' => 'nullable|string',
            'company_registration_number' => 'nullable|string|max:100',
            'tax_registration_number' => 'nullable|string|max:100',
            'tax_jurisdiction' => 'nullable|string|max:10',
            'tax_regime' => 'nullable|string|max:30',
            'tax_treatment' => 'nullable|string|max:40',
            'notes' => 'nullable|string',
            'terms' => 'nullable|string',
            'discount_amount' => 'nullable|numeric|min:0',
            'po_number' => 'nullable|string|max:100',
            'doctor_contact_id' => 'nullable|exists:core_contacts,id',
            'requestor_contact_id' => 'nullable|exists:core_contacts,id',
            'doctor_contact_name' => 'nullable|string|max:255',
            'requestor_contact_name' => 'nullable|string|max:255',
            'billing_contact_id' => 'nullable|exists:core_contacts,id',
            'shipping_contact_id' => 'nullable|exists:core_contacts,id',
            'billing_contact_name' => 'nullable|string|max:255',
            'shipping_contact_name' => 'nullable|string|max:255',
            'requires_approval' => 'nullable|boolean',
            'lines' => 'sometimes|array|min:1',
            'lines.*.line_type' => 'nullable|in:product,note,section',
            'lines.*.product_id' => 'nullable|exists:products,id',
            'lines.*.model_number' => 'nullable|string|max:100',
            'lines.*.description' => 'required|string',
            'lines.*.product_notes' => 'nullable|string',
            'lines.*.lot_number' => 'nullable|string|max:100',
            'lines.*.expiry_date' => 'nullable|date',
            'lines.*.quantity' => 'nullable|integer|min:0',
            'lines.*.unit_price' => 'required_if:lines.*.line_type,product|nullable|numeric|min:0',
            'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'lines.*.discount_amount' => 'nullable|numeric|min:0',
        ]);

        foreach (['doctor', 'requestor', 'billing', 'shipping'] as $contactType) {
            $nameKey = $contactType.'_contact_name';
            $idKey = $contactType.'_contact_id';
            if (! empty($validated[$nameKey])) {
                $validated[$idKey] = $this->resolveOrCreateContact($validated[$nameKey], 'person', 'invoice_form');
            }
            unset($validated[$nameKey]);
        }

        // Reject document discount exceeding the line subtotal
        $subtotal = isset($validated['lines'])
        ? array_reduce($validated['lines'], function ($sum, $line) {
            if (in_array($line['line_type'] ?? 'product', ['note', 'section'], true)) {
                return $sum;
            }

            return $sum + ($line['quantity'] * $line['unit_price']) - ($line['discount_amount'] ?? 0);
        }, 0)
        : $invoice->lines->reduce(function ($sum, $line) {
            return $sum + ($line->quantity * $line->unit_price) - ($line->discount_amount ?? 0);
        }, 0);
        $docDiscount = (float) ($validated['discount_amount'] ?? $invoice->discount_amount ?? 0);
        if ($docDiscount > 0 && $subtotal > 0 && $docDiscount > $subtotal) {
            return response()->json([
                'message' => "Discount amount ({$docDiscount}) exceeds the invoice subtotal ({$subtotal}).",
            ], 422);
        }

        DB::transaction(function () use ($invoice, $validated) {
            $lines = $validated['lines'] ?? null;
            unset($validated['lines']);

            $invoice->update($validated);

            if ($lines !== null) {
                $invoice->lines()->delete();
                foreach ($lines as $line) {
                    $invoice->lines()->create([
                        'line_type' => $line['line_type'] ?? 'product',
                        'sequence' => $line['sequence'] ?? 0,
                        'product_id' => $line['product_id'] ?? null,
                        'model_number' => $line['model_number'] ?? null,
                        'description' => $line['description'],
                        'product_notes' => $line['product_notes'] ?? null,
                        'lot_number' => $line['lot_number'] ?? null,
                        'expiry_date' => $line['expiry_date'] ?? null,
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'tax_rate' => $line['tax_rate'] ?? 0,
                        'discount_amount' => $line['discount_amount'] ?? 0,
                    ]);
                }
            }

            $invoice->refresh();
            $invoice->calculateTotals();

            // Issue #180: auto-promote `requires_approval` when the tenant's
            // threshold is set and the invoice total meets or exceeds it.
            // The existing `submitApproval` flow then handles the workflow.
            if ($this->approvalGate->requiresApproval(Invoice::class, (float) $invoice->total)) {
                if (! $invoice->requires_approval) {
                    $invoice->requires_approval = true;
                    $invoice->save();
                }
            }
        });

        return response()->json($invoice->fresh(['customer', 'lines', 'doctor', 'requestor', 'buyer', 'billingContact', 'shippingContact']));
    }

    public function send($id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        // Idempotent guard: already-posted invoices return success without re-posting
        if ($invoice->status === 'posted') {
            return response()->json([
                'message' => 'Invoice already posted',
                'invoice' => $invoice->fresh(['journalEntry', 'lines']),
            ]);
        }

        // Lock-date check: block posting into a closed period
        $lockResult = $this->lockDateValidator->validateTransactionDate('sales', $invoice->invoice_date);
        if ($lockResult['is_locked']) {
            return response()->json([
                'message' => $lockResult['message'],
                'lock_date' => $lockResult['lock_date'],
                'transaction_date' => $invoice->invoice_date->format('Y-m-d'),
            ], 422);
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
                $message = 'Default accounts not configured. '.
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

    public function submitApproval(Request $request, $id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::with('customer')->find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $this->approvalService->submit($invoice, $request->user(), $validated['reason'] ?? null);

            return response()->json([
                'message' => 'Invoice submitted for approval',
                'data' => $invoice->fresh(['approvalRequest', 'customer']),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function withdrawApproval(Request $request, $id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $this->approvalService->withdraw($invoice, $request->user(), $validated['reason'] ?? null);

            return response()->json([
                'message' => 'Invoice approval withdrawn',
                'data' => $invoice->fresh(['approvalRequest', 'customer']),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function deliver(Request $request, $id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        $validated = $request->validate([
            'channel' => 'required|string|in:email,whatsapp,portal',
            'document_type' => 'nullable|string|in:acceptance_copy,final_invoice,payment_request,reminder',
            'recipient' => 'nullable|email',
            'resend' => 'nullable|boolean',
        ]);

        $channel = $validated['channel'];
        $documentType = $validated['document_type'] ?? ($invoice->status === 'posted'
            ? 'final_invoice'
            : 'acceptance_copy');

        try {
            if (! empty($validated['resend'])) {
                $delivery = $this->deliveryService->resend($invoice, $request->user(), $channel, [
                    'document_type' => $documentType,
                    'recipient' => $validated['recipient'] ?? null,
                ]);
            } else {
                $delivery = $this->deliveryService->deliver($invoice, $channel, $request->user(), [
                    'document_type' => $documentType,
                    'recipient' => $validated['recipient'] ?? null,
                ]);
            }

            return response()->json([
                'message' => 'Invoice delivered',
                'data' => $delivery,
                'delivery_status' => $invoice->fresh()->delivery_status,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function uploadEvidence(Request $request, $id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        $validated = $request->validate([
            'type' => 'required|string|in:customer_esign,customer_acceptance,purchase_order,delivery_order,payment_proof,other',
            'file' => 'required|file|mimes:pdf,png,jpg,jpeg|max:10240',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $evidence = $this->evidenceService->upload(
                $invoice,
                $request->user(),
                $validated['type'],
                $validated['file'],
                $validated['notes'] ?? null,
            );

            return response()->json([
                'message' => 'Evidence uploaded',
                'data' => $evidence,
                'evidence_status' => $invoice->fresh()->evidence_status,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function createPaymentLink(Request $request, $id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        if ($invoice->status === 'draft') {
            return response()->json([
                'message' => 'Cannot create payment link for a draft invoice. Post the invoice first.',
                'code' => 'INVOICE_NOT_POSTED',
            ], 422);
        }

        if (bccomp($invoice->balance, '0.00', 2) <= 0) {
            return response()->json([
                'message' => 'Invoice balance is zero or paid. No payment link needed.',
            ], 422);
        }

        $validated = $request->validate([
            'amount' => 'nullable|numeric|min:0.01|max:'.$invoice->balance,
            'expires_in_days' => 'nullable|integer|min:1|max:90',
        ]);

        $amount = $validated['amount'] ?? $invoice->balance;
        $expiresDays = $validated['expires_in_days'] ?? 14;

        try {
            $paymentLink = $this->invoiceService->createPaymentLink($invoice, $amount, now()->addDays($expiresDays));

            $this->activityService->log(
                $invoice,
                \App\Models\InvoiceActivityLog::EVENT_PAYMENT_LINK_ISSUED,
                [
                    'payment_link_url' => $paymentLink['url'] ?? $invoice->payment_link_url,
                    'amount' => $amount,
                    'expires_at' => $invoice->payment_link_expires_at?->toIso8601String(),
                ],
                null,
                null,
                \App\Models\InvoiceActivityLog::ACTOR_USER,
                $request->user()->id,
            );

            // Auto-deliver payment link via email (non-blocking)
            try {
                if ($invoice->contact_email || $invoice->customer?->email) {
                    $this->deliveryService->deliver($invoice, 'email', $request->user(), [
                        'document_type' => \App\Models\InvoiceDelivery::DOCUMENT_PAYMENT_REQUEST,
                        'recipient' => $invoice->contact_email ?? $invoice->customer?->email,
                    ]);
                }
            } catch (\Exception $e) {
                \Log::warning('Payment link auto-delivery failed', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Payment link created',
                'data' => $paymentLink,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create payment link',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function createAcceptanceLink(Request $request, $id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::with('customer')->find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        $validated = $request->validate([
            'expires_in_days' => 'nullable|integer|min:1|max:90',
            'send_email' => 'nullable|boolean',
            'document_type' => 'nullable|string|in:final_invoice,acceptance_copy',
        ]);

        $expiresDays = $validated['expires_in_days'] ?? 30;
        $sendEmail = $validated['send_email'] ?? true;
        $documentType = $validated['document_type'] ?? 'final_invoice';

        try {
            $linkData = $this->evidenceService->createPortalAcceptanceLink($invoice, $expiresDays);

            $this->activityService->log(
                $invoice,
                \App\Models\InvoiceActivityLog::EVENT_ACCEPTANCE_LINK_CREATED,
                [
                    'acceptance_url' => $linkData['url'],
                    'expires_at' => $linkData['expires_at'],
                    'evidence_id' => $linkData['evidence_id'],
                    'send_email' => $sendEmail,
                ],
                $invoice->getOriginal('evidence_status') ?? $invoice->evidence_status,
                $invoice->fresh()->evidence_status,
                \App\Models\InvoiceActivityLog::ACTOR_USER,
                $request->user()->id,
            );

            $delivery = null;
            if ($sendEmail) {
                try {
                    $delivery = $this->deliveryService->deliver($invoice, 'email', $request->user(), [
                        'document_type' => $documentType,
                    ]);
                } catch (\Throwable $e) {
                    \Log::warning('Failed to send invoice email after acceptance link creation', [
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'message' => $sendEmail ? 'Acceptance link created and emailed' : 'Acceptance link created',
                'data' => [
                    ...$linkData,
                    'delivery' => $delivery ? ['id' => $delivery->id, 'status' => $delivery->status, 'recipient' => $delivery->recipient] : null,
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create acceptance link',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function sendForEsign(Request $request, $id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        $validated = $request->validate([
            'signers' => 'nullable|array',
            'signers.*.name' => 'required_with:signers|string|max:255',
            'signers.*.email' => 'required_with:signers|email|max:255',
        ]);

        $signers = $validated['signers'] ?? [];

        try {
            $result = $this->evidenceService->sendForEsign($invoice, $signers);

            return response()->json([
                'message' => 'Invoice sent for e-signature',
                'data' => $result,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to send for e-signature',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function getTimeline($id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        $timeline = $invoice->activityLog()
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'event' => $log->event,
                'actor_type' => $log->actor_type,
                'actor_id' => $log->actor_id,
                'from_state' => $log->from_state,
                'to_state' => $log->to_state,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        $approvals = $invoice->approvalRequest ? [
            [
                'id' => $invoice->approvalRequest->id,
                'request_number' => $invoice->approvalRequest->request_number,
                'status' => $invoice->approvalRequest->status,
                'amount' => $invoice->approvalRequest->amount,
                'requested_by' => $invoice->approvalRequest->requested_by,
                'requested_at' => $invoice->approvalRequest->requested_at?->toIso8601String(),
                'reviewed_by' => $invoice->approvalRequest->reviewed_by,
                'reviewed_at' => $invoice->approvalRequest->reviewed_at?->toIso8601String(),
                'review_notes' => $invoice->approvalRequest->review_notes,
                'expires_at' => $invoice->approvalRequest->expires_at?->toIso8601String(),
            ],
        ] : [];

        $deliveries = $invoice->deliveries()
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'channel' => $d->channel,
                'document_type' => $d->document_type,
                'recipient' => $d->recipient,
                'status' => $d->status,
                'error' => $d->error,
                'attempted_at' => $d->attempted_at?->toIso8601String(),
            ]);

        $evidence = $invoice->evidence()
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'type' => $e->type,
                'source' => $e->source,
                'file_name' => $e->file_name,
                'signed_at' => $e->signed_at?->toIso8601String(),
                'signer_email' => $e->signer_email,
                'uploaded_by' => $e->uploaded_by,
                'created_at' => $e->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'activity' => $timeline,
            'approvals' => $approvals,
            'deliveries' => $deliveries,
            'evidence' => $evidence,
        ]);
    }

    public function post(Request $request, $id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        if ($invoice->status === 'posted') {
            return response()->json([
                'message' => 'Invoice already posted',
                'data' => $invoice->fresh(['journalEntry', 'lines']),
            ]);
        }

        $approvalGuard = $this->approvalService->checkGuard($invoice);
        if ($approvalGuard['blocked']) {
            return response()->json([
                'message' => $approvalGuard['message'],
                'code' => $approvalGuard['code'],
                'approval_status' => $approvalGuard['approval_status'] ?? null,
            ], 422);
        }

        $evidenceGuard = $this->evidenceService->checkGuard($invoice);
        if ($evidenceGuard['blocked']) {
            return response()->json([
                'message' => $evidenceGuard['message'],
                'code' => $evidenceGuard['code'],
                'evidence_status' => $evidenceGuard['evidence_status'] ?? null,
                'required_types' => $evidenceGuard['required_types'] ?? null,
            ], 422);
        }

        $lockResult = $this->lockDateValidator->validateTransactionDate('sales', $invoice->invoice_date);
        if ($lockResult['is_locked']) {
            return response()->json([
                'message' => $lockResult['message'],
                'code' => 'PERIOD_LOCKED',
                'lock_date' => $lockResult['lock_date'],
                'transaction_date' => $invoice->invoice_date->format('Y-m-d'),
            ], 422);
        }

        try {
            $posted = $this->invoiceService->postInvoice($invoice);

            $this->activityService->log(
                $invoice,
                \App\Models\InvoiceActivityLog::EVENT_POSTED,
                [
                    'posted_by' => $request->user()->id,
                    'journal_entry_id' => $invoice->journal_entry_id,
                ],
                null,
                'posted',
                \App\Models\InvoiceActivityLog::ACTOR_USER,
                $request->user()->id,
            );

            try {
                $acceptance = $this->evidenceService->createPortalAcceptanceLink($invoice);

                $this->deliveryService->deliver($invoice, 'email', $request->user(), [
                    'document_type' => 'final_invoice',
                ]);

                $posted->acceptance_url = $acceptance['url'] ?? null;
                $posted->acceptance_expires_at = $acceptance['expires_at'] ?? null;
            } catch (\Throwable $e) {
                \Log::warning('Failed to auto-deliver posted invoice', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Invoice posted successfully',
                'data' => $posted,
            ]);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Cannot resolve GL account')) {
                return response()->json([
                    'message' => 'Default accounts not configured. Please go to Company Settings > Default Accounts and map the required GL accounts before posting invoices.',
                ], 422);
            }

            return response()->json([
                'message' => 'Failed to post invoice',
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Invoice post failed unexpectedly', [
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
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        if ($invoice->status !== 'draft') {
            return response()->json(['message' => 'Only draft invoices can be deleted'], 403);
        }

        $invoice->delete();

        return response()->json(['message' => 'Invoice deleted successfully']);
    }

    /**
     * Cancel a posted/sent/partial/overdue invoice (→ cancelled).
     * Blocks if payments exist — user must void/refund payments first.
     * This is an accounting-safe cancel: the journal entry remains for audit
     * but the invoice is marked as cancelled and excluded from reports.
     */
    public function cancel($id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        if (! in_array($invoice->status, ['posted', 'sent', 'partial', 'overdue'])) {
            return response()->json(['message' => 'Only posted, sent, partial, or overdue invoices can be cancelled'], 403);
        }

        if ($invoice->payments()->active()->exists()) {
            return response()->json([
                'message' => 'Cannot cancel an invoice with payments. Void or refund the payments first.',
            ], 422);
        }

        // Issue #169: a posted credit note has its own journal entry; cancelling
        // the invoice without voiding the credit note would leave that GL entry
        // backing a cancelled document.
        if ($invoice->creditNotes()->where('status', 'posted')->exists()) {
            return response()->json([
                'message' => 'Cannot cancel an invoice with posted credit notes. Void the credit notes first.',
            ], 422);
        }

        $invoice->update(['status' => 'cancelled']);

        return response()->json([
            'message' => 'Invoice cancelled',
            'data' => $invoice->fresh(['customer', 'lines']),
        ]);
    }

    /**
     * Reset a cancelled invoice back to draft.
     * Reverses the associated journal entry (if any) and clears the link.
     * Returns the invoice to draft state for re-editing and re-posting.
     */
    public function resetToDraft($id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        if ($invoice->status !== 'cancelled') {
            return response()->json(['message' => 'Only cancelled invoices can be reset to draft'], 403);
        }

        if ($invoice->payments()->active()->exists()) {
            return response()->json([
                'message' => 'Cannot reset an invoice that has payments. Void or refund the payments first.',
            ], 422);
        }

        // Issue #169: resetting reverses only the invoice's own journal entry —
        // a posted credit note's separate GL entry must not be orphaned.
        if ($invoice->creditNotes()->where('status', 'posted')->exists()) {
            return response()->json([
                'message' => 'Cannot reset an invoice that has posted credit notes. Void the credit notes first.',
            ], 422);
        }

        try {
            DB::transaction(function () use ($invoice) {
                // Reverse the journal entry if one exists
                if ($invoice->journal_entry_id) {
                    $this->reverseJournalEntry($invoice);
                }

                $invoice->update([
                    'status' => 'draft',
                    'journal_entry_id' => null,
                    'lifecycle_status' => 'draft',
                    'sent_at' => null,
                ]);
            });

            return response()->json([
                'message' => 'Invoice reset to draft',
                'data' => $invoice->fresh(['customer', 'lines']),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to reset invoice to draft', [
                'invoice_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to reset invoice to draft',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reverse a journal entry by creating a compensating entry.
     * Used when resetting a posted invoice to draft.
     *
     * The reversal is dated with the ORIGINAL journal entry's accounting date
     * (not the current system date) so both movements fall in the same
     * accounting period and net to zero in Revenue / AR / Tax.
     *
     * Routes through PostingEngine::reverse() (the single authoritative
     * reversal path, ADR-005 §15.3) so JournalEntryLink + PostingAuditLog
     * records are created and the reversal is idempotent.
     */
    private function reverseJournalEntry(Invoice $invoice): void
    {
        $journalEntry = \App\Models\JournalEntry::find($invoice->journal_entry_id);
        if (! $journalEntry) {
            return;
        }

        $originalDate = $journalEntry->entry_date instanceof \Carbon\Carbon
            ? $journalEntry->entry_date->toDateString()
            : (string) $journalEntry->entry_date;

        $result = app(\App\Accounting\PostingEngine::class)->reverse(new \App\Accounting\DTOs\ReversalRequest(
            journalEntryId: $journalEntry->id,
            reversalDate: $originalDate,
            reason: "Reset to draft: Invoice #{$invoice->invoice_number}",
            reasonCode: 'RESET_TO_DRAFT',
        ));

        if (! $result->success) {
            throw new \RuntimeException($result->error ?? 'Failed to create reversal journal entry');
        }

        // Update lifecycle on the original entry (status stays 'posted' — CHECK constraint)
        $journalEntry->update([
            'lifecycle_status' => 'reversed',
            'correction_type' => 'reverse',
            'is_active_version' => false,
        ]);

        // Release the (invoice, 'post') link so a fresh repost can create a new
        // post link + journal entry. Without this, PostingEngine::post()'s
        // idempotency guard would short-circuit the repost and re-link the
        // invoice to the (now reversed) original entry — making the new
        // posting invisible to the P&L.
        \App\Models\JournalEntryLink::where('source_document_type', 'invoice')
            ->where('source_document_id', $invoice->id)
            ->where('relation_type', 'post')
            ->delete();
    }

    /**
     * Bulk delete multiple draft invoices.
     * Only invoices that are still in draft (no journal entry / payments) are
     * removed. Non-deletable invoices are skipped and reported back.
     */
    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $ids = $validated['ids'];
        $deletable = Invoice::whereIn('id', $ids)->where('status', 'draft')->get();
        $deletedIds = [];
        $skipped = [];

        foreach ($deletable as $invoice) {
            try {
                $invoice->delete();
                $deletedIds[] = $invoice->id;
            } catch (\Throwable $e) {
                $skipped[] = ['id' => $invoice->id, 'reason' => $e->getMessage()];
            }
        }

        $notFoundIds = array_values(array_diff($ids, $deletable->pluck('id')->toArray()));
        foreach ($notFoundIds as $notFoundId) {
            $skipped[] = ['id' => $notFoundId, 'reason' => 'Not found or not a draft invoice'];
        }

        return response()->json([
            'message' => count($deletedIds).' invoice(s) deleted.',
            'deleted' => $deletedIds,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Bulk post (send) multiple draft customer invoices in a single action.
     * Used by the Bulk Post button on the invoice list page.
     */
    public function bulkPost(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $ids = $validated['ids'];
        $posted = [];
        $skipped = [];

        foreach ($ids as $id) {
            $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);
            if (! $invoice) {
                $skipped[] = ['id' => $id, 'reason' => 'Invoice not found'];

                continue;
            }

            // Idempotent: already-posted invoices are counted as success
            if ($invoice->status === 'posted') {
                $posted[] = $invoice->id;

                continue;
            }

            // Only draft invoices can be posted via Bulk Post
            if ($invoice->status !== 'draft') {
                $skipped[] = ['id' => $id, 'reason' => "Cannot post invoice in status '{$invoice->status}'. Only draft invoices can be posted."];

                continue;
            }

            // Lock-date check
            $lockResult = $this->lockDateValidator->validateTransactionDate('sales', $invoice->invoice_date);
            if ($lockResult['is_locked']) {
                $skipped[] = [
                    'id' => $id,
                    'reason' => $lockResult['message'] ?? "Date in closed period ({$invoice->invoice_date})",
                ];

                continue;
            }

            try {
                $this->invoiceService->postInvoice($invoice);
                $posted[] = $invoice->id;
            } catch (\Throwable $e) {
                $message = $e->getMessage();
                if (str_contains($message, 'Cannot resolve GL account')) {
                    $message = 'Default accounts not configured. Please go to Company Settings > Default Accounts and map the required GL accounts before posting invoices.';
                }
                \Log::warning('Bulk invoice posting failed', [
                    'invoice_id' => $id,
                    'error' => $e->getMessage(),
                ]);
                $skipped[] = ['id' => $id, 'reason' => $message];
            }
        }

        return response()->json([
            'message' => count($posted).' invoice(s) posted.',
            'posted' => $posted,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Bulk import invoices/quotations from CSV.
     *
     * One row = one line item. Rows are grouped into a single invoice by
     * `invoice_number` (rows that share an invoice_number become lines on the
     * same invoice). Rows with a blank invoice_number each become a single-line
     * invoice. Imported invoices are created as drafts ( quotations);
     * set the `status` column to `sent` to also post them.
     */
    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt']);

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            return response()->json(['message' => 'Unable to read file'], 400);
        }

        $rawHeader = fgetcsv($handle);
        if (! is_array($rawHeader) || count($rawHeader) === 0) {
            fclose($handle);

            return response()->json(['message' => 'CSV header missing'], 422);
        }

        $header = array_map(function ($h) {
            $h = strtolower(trim((string) $h));
            $h = preg_replace('/[^a-z0-9]+/', '_', $h);

            return trim($h, '_');
        }, $rawHeader);

        $columnAliases = [
            'invoice_no' => 'invoice_number',
            'quote_number' => 'invoice_number',
            'quote_no' => 'invoice_number',
            'number' => 'invoice_number',
            'customer' => 'customer_name',
            'customer_code' => 'customer_code',
            'customer_name' => 'customer_name',
            'date' => 'invoice_date',
            'issue_date' => 'invoice_date',
            'valid_until' => 'due_date',
            'invoice_expiry_date' => 'due_date',
            'reference' => 'customer_reference',
            'po_number' => 'po_number',
            'sku' => 'product_sku',
            'product_sku' => 'product_sku',
            'item' => 'description',
            'item_description' => 'description',
            'lot' => 'lot_number',
            'batch' => 'lot_number',
            'batch_number' => 'lot_number',
            'qty' => 'quantity',
            'price' => 'unit_price',
            'unit_price' => 'unit_price',
            'rate' => 'unit_price',
            'tax' => 'tax_rate',
            'line_discount' => 'line_discount_amount',
            'line_discount_amount' => 'line_discount_amount',
            'discount' => 'discount_amount',
            'billing_address' => 'billing_address_snapshot',
            'shipping_address' => 'shipping_address_snapshot',
            'company_reg_no' => 'company_registration_number',
            'tax_reg_no' => 'tax_registration_number',
            'email' => 'contact_email',
            'phone' => 'contact_phone',
            'case_date' => 'date_of_case',
            'case_no' => 'case_number',
            'case_number' => 'case_number',
            'location' => 'case_location',
            'patient' => 'patient_name',
            'cost_center' => 'cost_center_id',
            'delivery_date' => 'delivery_date',
            'doctor' => 'doctor_name',
            'requestor' => 'requestor_name',
            'buyer' => 'buyer_name',
            'billing_contact' => 'billing_contact_name',
            'shipping_contact' => 'shipping_contact_name',
            'delivery_contact' => 'shipping_contact_name',
            'case_no' => 'medical_case_no',
            'case_tag' => 'case_tag',
            'patient_name' => 'patient_name',
            'delivered_to' => 'delivered_to',
            'other_note' => 'other_note',
        ];
        $header = array_map(fn ($key) => $columnAliases[$key] ?? $key, $header);

        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            if (! is_array($data) || count(array_filter($data, fn ($v) => (string) $v !== '')) === 0) {
                continue;
            }
            $row = [];
            foreach ($header as $idx => $key) {
                if ($key !== '' && isset($data[$idx])) {
                    // Sanitize to valid UTF-8 — Excel CSVs often contain Windows-1252
                    // bytes (e.g. 0x92 right-apostrophe) that silently break PostgreSQL INSERTs
                    $val = $data[$idx];
                    if (! mb_check_encoding($val, 'UTF-8')) {
                        $val = mb_convert_encoding($val, 'UTF-8', 'UTF-8');
                    }
                    $row[$key] = $val;
                }
            }
            if (empty($row)) {
                continue;
            }
            $rows[] = $row;
        }
        fclose($handle);

        if (count($rows) === 0) {
            return response()->json(['message' => 'No rows found'], 422);
        }

        // Group rows into invoices by invoice_number (blank number = 1 invoice per row)
        $groups = [];
        $order = [];
        foreach ($rows as $idx => $row) {
            $num = trim((string) ($row['invoice_number'] ?? ''));
            $key = $num !== '' ? 'num:'.$num : 'row:'.$idx;
            if (! isset($groups[$key])) {
                $groups[$key] = ['first_index' => $idx, 'header' => $row, 'lines' => []];
                $order[] = $key;
            }
            $groups[$key]['lines'][] = $row;
        }

        $created = 0;
        $errors = [];
        $customerCache = [];
        $productCache = [];

        foreach ($order as $key) {
            $group = $groups[$key];
            $firstIndex = $group['first_index'];
            $headerRow = $group['header'];

            try {
                // Re-apply tenant search_path on every iteration to survive connection resets
                $schema = tenant()->getSchemaName();
                if ($schema) {
                    DB::statement("SET search_path TO \"{$schema}\", public");
                }

                $customer = $this->resolveImportCustomer($headerRow, $customerCache);
                if (! $customer) {
                    $code = trim((string) ($headerRow['customer_code'] ?? ''));
                    $name = trim((string) ($headerRow['customer_name'] ?? ''));
                    $id = trim((string) ($headerRow['customer_id'] ?? ''));
                    $parts = [];
                    if ($code !== '') {
                        $parts[] = "code={$code}";
                    }
                    if ($name !== '') {
                        $parts[] = "name={$name}";
                    }
                    if ($id !== '') {
                        $parts[] = "id={$id}";
                    }
                    $detail = $parts !== '' ? implode(', ', $parts) : 'no customer_code, customer_name, or customer_id provided';
                    throw new \RuntimeException("Customer not found: {$detail}");
                }

                $lines = [];
                foreach ($group['lines'] as $lineRow) {
                    $description = trim((string) ($lineRow['description'] ?? ''));
                    $quantity = (int) round($this->parseNumericValue($lineRow['quantity'] ?? null));
                    if ($quantity < 1) {
                        $quantity = 1;
                    }
                    $rawUnitPrice = $this->readImportValue($lineRow['unit_price'] ?? null);
                    $hasExplicitUnitPrice = $rawUnitPrice !== null;
                    $unitPrice = $this->parseNumericValue($rawUnitPrice);
                    $taxRate = $this->parseNumericValue($lineRow['tax_rate'] ?? null);
                    $lineDiscount = $this->parseNumericValue($lineRow['line_discount_amount'] ?? null);

                    $line = [
                        'description' => $description !== '' ? $description : 'Imported line',
                        'model_number' => $this->readImportValue($lineRow['model_number'] ?? null),
                        'product_notes' => $this->readImportValue($lineRow['product_notes'] ?? null),
                        'lot_number' => $this->readImportValue($lineRow['lot_number'] ?? null),
                        'expiry_date' => $this->parseImportDate($lineRow['expiry_date'] ?? null),
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'tax_rate' => $taxRate,
                        'discount_amount' => $lineDiscount,
                    ];

                    $sku = trim((string) ($lineRow['product_sku'] ?? ''));
                    if ($sku !== '' && $sku !== '-') {
                        $product = $this->resolveImportProduct($sku, $productCache);
                        if ($product) {
                            $line['product_id'] = $product->id;
                            if ($description === '') {
                                $line['description'] = $product->name;
                            }
                            if (! $hasExplicitUnitPrice) {
                                // Issue #132: honour the product's price tiers
                                // (volume/customer/variant/date) when the import
                                // row does not specify a unit price.
                                $line['unit_price'] = $product->getPriceForQuantity(
                                    (int) ceil((float) ($line['quantity'] ?? 1)),
                                    $customer?->id,
                                );
                            }
                        }
                    }

                    $lines[] = $line;
                }

                if (count($lines) === 0) {
                    throw new \RuntimeException('Invoice has no lines');
                }

                $data = [
                    'customer_id' => $customer->id,
                    'lines' => $lines,
                ];

                // Preserve the imported invoice/quote number when provided.
                $importedNumber = trim((string) ($headerRow['invoice_number'] ?? ''));
                if ($importedNumber !== '') {
                    $data['invoice_number'] = $importedNumber;
                }

                foreach ([
                    'invoice_date', 'due_date', 'customer_reference', 'internal_reference',
                    'attention_to', 'contact_email', 'contact_phone',
                    'billing_address_snapshot', 'shipping_address_snapshot',
                    'company_registration_number', 'tax_registration_number',
                    'notes', 'terms', 'discount_amount',
                    'po_number', 'case_number', 'pr_no', 'contract_no', 'plant_code', 'company_code', 'date_of_case', 'case_location', 'patient_name', 'buyer_contact_id',
                    'cost_center_id', 'delivery_date',
                ] as $field) {
                    $value = $this->readImportValue($headerRow[$field] ?? null);
                    if ($value !== null) {
                        $data[$field] = $field === 'discount_amount'
                            ? $this->parseNumericValue($value)
                            : $value;
                    }
                }

                foreach (['billing', 'shipping'] as $addressType) {
                    $snapshot = $this->buildImportAddressSnapshot($headerRow, $addressType);
                    if ($snapshot !== null) {
                        $data[$addressType.'_address_snapshot'] = $snapshot;
                    }
                }

                // Resolve doctor and requestor names to contact IDs (auto-create if not found)
                $doctorName = $this->readImportValue($headerRow['doctor_name'] ?? $headerRow['doctor'] ?? null);
                if ($doctorName !== null) {
                    $this->resolveOrCreateImportCustomerContact($customer, $doctorName, 'doctor', $headerRow);
                    $data['doctor_contact_id'] = $this->resolveOrCreateContact($doctorName, 'person', 'import_invoice');
                    $data['_doctor_name'] = $doctorName;
                }
                $requestorName = $this->readImportValue($headerRow['requestor_name'] ?? $headerRow['requestor'] ?? null);
                if ($requestorName !== null) {
                    $this->resolveOrCreateImportCustomerContact($customer, $requestorName, 'requestor', $headerRow);
                    $data['requestor_contact_id'] = $this->resolveOrCreateContact($requestorName, 'person', 'import_invoice');
                    $data['_requestor_name'] = $requestorName;
                }
                $buyerName = $this->readImportValue($headerRow['buyer_name'] ?? $headerRow['buyer'] ?? null);
                if ($buyerName !== null) {
                    $data['buyer_contact_id'] = $this->resolveOrCreateContact($buyerName, 'person', 'import_invoice');
                }
                $billingContactName = $this->readImportValue($headerRow['billing_contact_name'] ?? $headerRow['billing_contact'] ?? null);
                if ($billingContactName !== null) {
                    $this->resolveOrCreateImportCustomerContact($customer, $billingContactName, 'billing', $headerRow);
                    $data['billing_contact_id'] = $this->resolveOrCreateContact($billingContactName, 'person', 'import_invoice');
                    $data['_billing_name'] = $billingContactName;
                }
                $shippingContactName = $this->readImportValue($headerRow['shipping_contact_name'] ?? $headerRow['shipping_contact'] ?? null);
                if ($shippingContactName !== null) {
                    $this->resolveOrCreateImportCustomerContact($customer, $shippingContactName, 'shipping', $headerRow);
                    $data['shipping_contact_id'] = $this->resolveOrCreateContact($shippingContactName, 'person', 'import_invoice');
                    $data['_shipping_name'] = $shippingContactName;
                }

                $this->syncImportCustomerAddress($customer, $headerRow, 'billing');
                $this->syncImportCustomerAddress($customer, $headerRow, 'shipping');

                $invoiceDate = $this->parseImportDate($headerRow['invoice_date'] ?? null) ?? now()->toDateString();
                $data['invoice_date'] = $invoiceDate;
                if (($parsedDue = $this->parseImportDate($headerRow['due_date'] ?? null)) !== null) {
                    $data['due_date'] = $parsedDue;
                }

                // Lock-date guard
                $lockResult = $this->lockDateValidator->validateTransactionDate('sales', $invoiceDate);
                if ($lockResult['is_locked']) {
                    throw new \RuntimeException("Date in closed period ({$invoiceDate})");
                }

                $invoice = $this->createInvoiceFromData($data);

                // Create medical case if case data is present
                $caseData = [
                    'case_no' => $this->readImportValue($headerRow['medical_case_no'] ?? $headerRow['case_no'] ?? null),
                    'case_tag' => $this->readImportValue($headerRow['case_tag'] ?? null),
                    'case_date' => $this->parseImportDate($headerRow['case_date'] ?? null),
                    'patient_name' => $this->readImportValue($headerRow['patient_name'] ?? null),
                    'delivered_to' => $this->readImportValue($headerRow['delivered_to'] ?? null),
                    'other_note' => $this->readImportValue($headerRow['other_note'] ?? null),
                ];
                try {
                    $medicalCase = $this->caseImportService->createCaseFromInvoiceImport($invoice, $caseData);
                    if ($medicalCase) {
                        Log::info('Medical case created from import', [
                            'invoice_id' => $invoice->id,
                            'case_id' => $medicalCase->id,
                            'case_no' => $medicalCase->case_no,
                        ]);
                    }
                } catch (\Throwable $caseError) {
                    Log::warning('Failed to create medical case from import', [
                        'invoice_id' => $invoice->id,
                        'error' => $caseError->getMessage(),
                    ]);
                    $errors[] = [
                        'row' => $firstIndex + 2,
                        'message' => 'Invoice '.$invoice->invoice_number.' created but case creation failed: '.$caseError->getMessage(),
                    ];
                }

                // Optional: post the invoice if status = sent/posted
                $status = strtolower(trim((string) ($headerRow['status'] ?? '')));
                if (in_array($status, ['sent', 'posted', 'paid'], true) && $invoice->status !== 'posted') {
                    try {
                        $this->invoiceService->postInvoice($invoice);
                    } catch (\Throwable $postException) {
                        // Keep as draft (quote) but warn — posting may need GL accounts configured
                        $errors[] = [
                            'row' => $firstIndex + 2,
                            'message' => 'Invoice '.$invoice->invoice_number.' created as draft (posting failed: '.$postException->getMessage().')',
                        ];
                    }
                }

                $created++;
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                $errors[] = [
                    'row' => $firstIndex + 2,
                    'message' => 'Invoice '.trim((string) ($headerRow['invoice_number'] ?? ('row '.($firstIndex + 2)))).': '.$msg,
                ];
            }
        }

        return response()->json([
            'created' => $created,
            'errors' => $errors,
        ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function resolveImportCustomer(array $row, array &$cache): ?Customer
    {
        $code = trim((string) ($row['customer_code'] ?? ''));
        $name = trim((string) ($row['customer_name'] ?? ''));
        $id = trim((string) ($row['customer_id'] ?? ''));

        $cacheKey = $code.'|'.$name.'|'.$id;
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $customer = null;
        if ($code !== '' && $code !== '-') {
            $customer = Customer::withTrashed()->whereRaw('LOWER(code) = ?', [strtolower($code)])->first();
        }
        if (! $customer && $name !== '' && $name !== '-') {
            $customer = Customer::withTrashed()->whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
        }
        if (! $customer && $id !== '' && filter_var($id, FILTER_VALIDATE_INT) !== false) {
            $customer = Customer::withTrashed()->find((int) $id);
        }

        return $cache[$cacheKey] = $customer;
    }

    private function resolveImportProduct(string $sku, array &$cache): ?Product
    {
        if (isset($cache[$sku])) {
            return $cache[$sku];
        }

        return $cache[$sku] = Product::where('sku', $sku)->first();
    }

    private function readImportValue($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = $this->sanitizeUtf8(trim((string) $value));

        return ($value === '' || $value === '-') ? null : $value;
    }

    private function buildImportAddressSnapshot(array $row, string $prefix): ?string
    {
        $parts = [];
        foreach (['label', 'street', 'street2', 'city', 'state', 'zip', 'country'] as $field) {
            $value = $this->readImportValue($row[$prefix.'_'.$field] ?? null);
            if ($value !== null) {
                $parts[] = $value;
            }
        }

        return $parts === [] ? null : implode("\n", $parts);
    }

    private function importAddressData(array $row, string $prefix): array
    {
        $data = [];
        foreach (['label', 'street', 'street2', 'city', 'state', 'zip', 'country'] as $field) {
            $value = $this->readImportValue($row[$prefix.'_'.$field] ?? null);
            if ($value !== null) {
                $data[$field] = $value;
            }
        }

        return $data;
    }

    private function syncImportCustomerAddress(Customer $customer, array $row, string $type): void
    {
        $addressData = $this->importAddressData($row, $type);
        if ($addressData === []) {
            return;
        }

        $query = $customer->addresses()->where('type', $type);
        $address = isset($addressData['label'])
            ? (clone $query)->whereRaw('LOWER(label) = ?', [strtolower($addressData['label'])])->first()
            : null;
        $address ??= (clone $query)->where('is_default', true)->first();
        $address ??= $query->first();

        if ($address) {
            $address->update($addressData);

            return;
        }

        $customer->addresses()->create([
            ...$addressData,
            'type' => $type,
            'is_default' => ! $customer->addresses()->where('type', $type)->exists(),
        ]);
    }

    private function resolveOrCreateImportCustomerContact(
        Customer $customer,
        string $name,
        string $category,
        array $row
    ): \App\Models\CustomerContact {
        $contact = $customer->customerContacts()
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->first();

        $contactData = [
            'contact_category' => $category,
            'contact_type' => $this->readImportValue(
                $row[$category.'_contact_type'] ?? ($category === 'billing' ? ($row['type'] ?? null) : null)
            ) ?? $category,
        ];

        if ($category === 'billing') {
            $email = $this->readImportValue($row['contact_email'] ?? null);
            $phone = $this->readImportValue($row['contact_phone'] ?? null);
            if ($email !== null) {
                $contactData['email'] = $email;
            }
            if ($phone !== null) {
                $contactData['phone'] = $phone;
            }
        }

        $contactData = [...$contactData, ...$this->importAddressData($row, $category)];

        if ($contact) {
            $contact->update($contactData);

            return $contact;
        }

        return $customer->customerContacts()->create([
            'name' => $name,
            ...$contactData,
        ]);
    }

    /**
     * Ensure the string is valid UTF-8.
     * CSV files exported from Excel often contain Windows-1252 bytes (e.g. 0x92
     * for the right single quotation mark) that are invalid UTF-8. PostgreSQL
     * silently rejects INSERTs containing these bytes, causing data loss.
     */
    private function sanitizeUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    /**
     * Find a contact by name (case-insensitive) or create one if not found.
     * Uses Eloquent model for both read and write to ensure the same connection
     * and search_path are used throughout — DB::statement() bypasses the query
     * builder and can silently use a different PDO connection.
     */
    private function resolveOrCreateContact(string $name, string $type = 'person', string $source = 'import'): ?string
    {
        // Sanitize to valid UTF-8 — CSV files from Excel often contain Windows-1252
        // bytes (e.g. 0x92) that cause PostgreSQL to silently reject the INSERT.
        if (! mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        }

        $normalised = strtolower(trim($name));

        // Try to find an existing contact (scoped to current tenant)
        $tenantId = tenant()->id;
        $contact = \Modules\Contact\src\Models\CoreContact::where('tenant_id', $tenantId)->whereRaw('LOWER(display_name) = ?', [$normalised])->first()
            ?? \Modules\Contact\src\Models\CoreContact::where('tenant_id', $tenantId)->whereRaw('LOWER(first_name || \' \' || last_name) = ?', [$normalised])->first()
            ?? \Modules\Contact\src\Models\CoreContact::where('tenant_id', $tenantId)->whereRaw('LOWER(company_name) = ?', [$normalised])->first();

        if ($contact) {
            return $contact->id;
        }

        // Split name into first/last for person contacts
        $parts = array_filter(explode(' ', trim($name)));
        $firstName = $parts ? array_shift($parts) : $name;
        $lastName = $parts ? implode(' ', $parts) : null;

        // Use Eloquent create() so the INSERT goes through the same connection
        // and search_path as the SELECT queries above.
        $contact = \Modules\Contact\src\Models\CoreContact::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'contact_type' => $type,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'display_name' => trim($name),
            'company_name' => $lastName === null ? $name : null,
            'source' => $source,
            'is_active' => true,
        ]);

        return $contact->id;
    }

    private function parseNumericValue($value): float
    {
        if ($value === null || trim((string) $value) === '') {
            return 0;
        }
        $cleaned = preg_replace('/[^0-9.]/', '', trim((string) $value));

        return $cleaned !== '' ? (float) $cleaned : 0;
    }

    private function parseImportDate($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $raw = trim((string) $value);
        if ($raw === '' || $raw === '-') {
            return null;
        }
        // Slash-separated dates prefer day/month/year. US month/day values are
        // still accepted when they cannot be interpreted as DD/MM/YYYY.
        foreach (['Y-m-d', 'd/m/Y', 'j/n/Y', 'j/n/y', 'm/d/Y', 'n/j/Y', 'n/j/y', 'd-m-Y', 'Y/m/d'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $raw);
            if ($dt instanceof \DateTimeImmutable && $dt->format($format) === $raw) {
                return $dt->format('Y-m-d');
            }
        }
        try {
            return (new \DateTimeImmutable($raw))->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
