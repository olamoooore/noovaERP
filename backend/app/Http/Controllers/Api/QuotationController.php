<?php

namespace App\Http\Controllers\Api;

use App\Exports\QuotationsExport;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Warehouse;
use App\Services\CaseImportService;
use App\Services\InvoiceService;
use App\Services\LockDateValidationService;
use App\Services\SalesApprovalGate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Sales\Services\SalesOrderService;
use Modules\TemplateBuilder\Services\DataSource\CompanyDataSource;

class QuotationController extends Controller
{
    public function __construct(
        private SalesOrderService $salesOrderService,
        private InvoiceService $invoiceService,
        private LockDateValidationService $lockDateValidator,
        private SalesApprovalGate $approvalGate,
        private CaseImportService $caseImportService,
    ) {}

    /**
     * Mirror quotation lifecycle changes into the chatter History feed.
     * Chatter failures must never prevent the document transition itself.
     */
    private function logQuotationChatter(
        Quotation $quotation,
        string $description,
        array $properties = [],
    ): void {
        try {
            app(\App\Services\Chatter\ChatterService::class)->logEvent(
                Quotation::class,
                (int) $quotation->id,
                'status_changed',
                $description,
                $properties,
                Auth::id(),
                Auth::check() ? get_class(Auth::user()) : null,
            );
        } catch (\Throwable $e) {
            Log::warning('QuotationController: chatter mirror failed', [
                'quotation_id' => $quotation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function index(Request $request)
    {
        $query = $this->filteredQuotationQuery($request);

        if ($request->boolean('ids_only')) {
            $ids = $query->whereIn('status', ['draft', 'sent', 'confirmed'])->pluck('id');

            return response()->json(['data' => $ids, 'total' => $ids->count()]);
        }

        $query->with(['customer', 'lines']);

        return response()->json($query->paginate($request->per_page ?? 20));
    }

    public function export(Request $request)
    {
        $validated = $request->validate([
            'columns' => ['required', 'array', 'min:1', 'max:'.count(QuotationsExport::COLUMNS)],
            'columns.*' => ['required', 'string', 'distinct', 'in:'.implode(',', array_keys(QuotationsExport::COLUMNS))],
            'status' => ['nullable', 'string'],
            'customer_id' => ['nullable'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'search' => ['nullable', 'string', 'max:255'],
            'document_ids' => ['nullable', 'array', 'max:1000'],
            'document_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        return Excel::download(
            new QuotationsExport($this->filteredQuotationQuery($request), $validated['columns']),
            'quotations_'.now()->format('Y-m-d').'.xlsx'
        );
    }

    private function filteredQuotationQuery(Request $request)
    {
        $query = Quotation::query()->orderBy('quotation_date', 'desc')->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $statuses = explode(',', $request->status);
            $query->whereIn('status', $statuses);
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('document_ids')) {
            $query->whereIn('id', $request->input('document_ids', []));
        }

        if ($request->filled('start_date')) {
            $query->whereDate('quotation_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('quotation_date', '<=', $request->end_date);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('quotation_number', 'ILIKE', "%{$search}%")
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('name', 'ILIKE', "%{$search}%");
                    });
            });
        }

        return $query;
    }

    public function store(Request $request)
    {
        if ($request->input('discount_type') === 'percentage') {
            $request->validate(['discount_value' => 'nullable|numeric|min:0|max:100']);
        }

        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            // Operators may override the generated number (same behaviour as
            // invoices); uniqueness is scoped to the tenant schema.
            'quotation_number' => 'nullable|string|max:60|unique:quotations,quotation_number',
            'quotation_date' => 'nullable|date',
            'valid_until' => 'nullable|date',
            'customer_reference' => 'nullable|string|max:100',
            'internal_reference' => 'nullable|string|max:255',
            'credit_terms' => 'nullable|string|max:255',
            'attention_to' => 'nullable|string|max:255',
            'billing_contact_id' => 'nullable|exists:core_contacts,id',
            'shipping_contact_id' => 'nullable|exists:core_contacts,id',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'salesperson' => 'nullable|string|max:255',
            'delivery_lead_time' => 'nullable|string|max:255',
            'shipping_method' => 'nullable|string|max:255',
            'shipping_terms' => 'nullable|string|max:255',
            'validity' => 'nullable|string|max:255',
            'case_number' => 'nullable|string|max:100',
            'date_of_case' => 'nullable|date',
            'case_location' => 'nullable|string|max:255',
            'patient_name' => 'nullable|string|max:255',
            'cost_center_id' => 'nullable|string|max:100',
            'delivery_date' => 'nullable|date',
            'pr_no' => 'nullable|string|max:100',
            'contract_no' => 'nullable|string|max:100',
            'plant_code' => 'nullable|string|max:100',
            'company_code' => 'nullable|string|max:100',
            'billing_address_snapshot' => 'nullable|string',
            'shipping_address_snapshot' => 'nullable|string',
            'notes' => 'nullable|string',
            'terms' => 'nullable|string',
            'discount_amount' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:fixed,percentage',
            'discount_value' => 'nullable|numeric|min:0|required_with:discount_type',
            'lines' => 'required|array|min:1',
            'lines.*.line_type' => 'nullable|in:product,note,section',
            'lines.*.product_id' => 'nullable|exists:products,id',
            'lines.*.model_number' => 'nullable|string|max:100',
            'lines.*.description' => 'required|string',
            'lines.*.product_notes' => 'nullable|string',
            'lines.*.quantity' => 'required_if:lines.*.line_type,product|nullable|integer|min:0',
            'lines.*.unit_price' => 'required_if:lines.*.line_type,product|nullable|numeric|min:0',
            'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'lines.*.discount_amount' => 'nullable|numeric|min:0',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:100',
        ]);

        try {
            $quotation = DB::transaction(function () use ($validated) {
                $data = array_filter([
                    'quotation_number' => isset($validated['quotation_number']) ? trim($validated['quotation_number']) : null,
                    'quotation_date' => $validated['quotation_date'] ?? now()->toDateString(),
                    'valid_until' => $validated['valid_until'] ?? null,
                    'customer_id' => $validated['customer_id'],
                    'customer_reference' => $validated['customer_reference'] ?? null,
                    'internal_reference' => $validated['internal_reference'] ?? null,
                    'credit_terms' => $validated['credit_terms'] ?? null,
                    'attention_to' => $validated['attention_to'] ?? null,
                    'billing_contact_id' => $validated['billing_contact_id'] ?? null,
                    'shipping_contact_id' => $validated['shipping_contact_id'] ?? null,
                    'contact_email' => $validated['contact_email'] ?? null,
                    'contact_phone' => $validated['contact_phone'] ?? null,
                    'salesperson' => $validated['salesperson'] ?? null,
                    'delivery_lead_time' => $validated['delivery_lead_time'] ?? null,
                    'shipping_method' => $validated['shipping_method'] ?? null,
                    'shipping_terms' => $validated['shipping_terms'] ?? null,
                    'validity' => $validated['validity'] ?? null,
                    'case_number' => $validated['case_number'] ?? null,
                    'date_of_case' => $validated['date_of_case'] ?? null,
                    'case_location' => $validated['case_location'] ?? null,
                    'patient_name' => $validated['patient_name'] ?? null,
                    'cost_center_id' => $validated['cost_center_id'] ?? null,
                    'delivery_date' => $validated['delivery_date'] ?? null,
                    'pr_no' => $validated['pr_no'] ?? null,
                    'contract_no' => $validated['contract_no'] ?? null,
                    'plant_code' => $validated['plant_code'] ?? null,
                    'company_code' => $validated['company_code'] ?? null,
                    'billing_address_snapshot' => $validated['billing_address_snapshot'] ?? null,
                    'shipping_address_snapshot' => $validated['shipping_address_snapshot'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'terms' => $validated['terms'] ?? null,
                    'discount_amount' => $validated['discount_amount'] ?? null,
                    'discount_type' => $validated['discount_type'] ?? 'fixed',
                    'discount_value' => $validated['discount_value'] ?? ($validated['discount_amount'] ?? null),
                    'created_by' => Auth::id(),
                ], fn ($v) => $v !== null);

                $quotation = Quotation::create($data);

                foreach ($validated['lines'] as $lineData) {
                    $lineType = $lineData['line_type'] ?? 'product';
                    if ($lineType === 'product' && empty($lineData['product_id'])) {
                        throw new \InvalidArgumentException('Product lines require a product.');
                    }
                    $quotation->lines()->create([
                        'line_type' => $lineType,
                        'sequence' => $lineData['sequence'] ?? 0,
                        'product_id' => $lineData['product_id'] ?? null,
                        'model_number' => $lineData['model_number'] ?? null,
                        'description' => $lineData['description'],
                        'product_notes' => $lineData['product_notes'] ?? null,
                        'quantity' => $lineData['quantity'],
                        'unit_price' => $lineData['unit_price'],
                        'tax_rate' => $lineData['tax_rate'] ?? 0,
                        'discount_amount' => $lineData['discount_amount'] ?? 0,
                    ]);
                }

                $quotation->refresh();
                $quotation->calculateTotals();

                return $quotation->load(['customer', 'lines.product']);
            });

            $tagNames = $validated['tags'] ?? [];
            if (is_array($tagNames) && $tagNames !== []) {
                app(\App\Services\TagService::class)->attach($quotation, $tagNames, Auth::id());
            }

            return response()->json([
                'message' => 'Quotation created successfully',
                'data' => $quotation,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create quotation',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        $quotation = Quotation::with(['customer', 'lines.product', 'salesOrder', 'invoices', 'billingContact', 'shippingContact', 'deliveryOrders'])->find($id);

        if (! $quotation) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }

        return response()->json($quotation);
    }

    public function update(Request $request, $id)
    {
        $quotation = Quotation::find($id);

        if (! $quotation) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }

        if ($quotation->status !== 'draft') {
            return response()->json(['message' => 'Only draft quotations can be edited'], 403);
        }

        if ($request->input('discount_type') === 'percentage') {
            $request->validate(['discount_value' => 'nullable|numeric|min:0|max:100']);
        }

        $validated = $request->validate([
            'customer_id' => 'exists:customers,id',
            'quotation_date' => 'date',
            'valid_until' => 'nullable|date',
            'customer_reference' => 'nullable|string|max:100',
            'internal_reference' => 'nullable|string|max:255',
            'credit_terms' => 'nullable|string|max:255',
            'attention_to' => 'nullable|string|max:255',
            'billing_contact_id' => 'nullable|exists:core_contacts,id',
            'shipping_contact_id' => 'nullable|exists:core_contacts,id',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'salesperson' => 'nullable|string|max:255',
            'delivery_lead_time' => 'nullable|string|max:255',
            'shipping_method' => 'nullable|string|max:255',
            'shipping_terms' => 'nullable|string|max:255',
            'validity' => 'nullable|string|max:255',
            'case_number' => 'nullable|string|max:100',
            'date_of_case' => 'nullable|date',
            'case_location' => 'nullable|string|max:255',
            'patient_name' => 'nullable|string|max:255',
            'cost_center_id' => 'nullable|string|max:100',
            'delivery_date' => 'nullable|date',
            'pr_no' => 'nullable|string|max:100',
            'contract_no' => 'nullable|string|max:100',
            'plant_code' => 'nullable|string|max:100',
            'company_code' => 'nullable|string|max:100',
            'billing_address_snapshot' => 'nullable|string',
            'shipping_address_snapshot' => 'nullable|string',
            'notes' => 'nullable|string',
            'terms' => 'nullable|string',
            'discount_amount' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:fixed,percentage',
            'discount_value' => 'nullable|numeric|min:0|required_with:discount_type',
            'lines' => 'nullable|array|min:1',
            'lines.*.line_type' => 'nullable|in:product,note,section',
            'lines.*.sequence' => 'nullable|integer|min:0',
            'lines.*.product_id' => 'nullable|exists:products,id',
            'lines.*.model_number' => 'nullable|string|max:100',
            'lines.*.description' => 'required_with:lines|string',
            'lines.*.product_notes' => 'nullable|string',
            'lines.*.quantity' => 'nullable|integer|min:0',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'lines.*.discount_amount' => 'nullable|numeric|min:0',
        ]);

        if (array_key_exists('discount_amount', $validated) && ! array_key_exists('discount_value', $validated)) {
            $validated['discount_type'] = 'fixed';
            $validated['discount_value'] = $validated['discount_amount'];
        }

        $quotation->update($validated);

        if ($request->has('lines')) {
            $quotation->lines()->delete();
            foreach ($request->lines as $lineData) {
                $quotation->lines()->create([
                    'line_type' => $lineData['line_type'] ?? 'product',
                    'sequence' => $lineData['sequence'] ?? 0,
                    'product_id' => $lineData['product_id'] ?? null,
                    'model_number' => $lineData['model_number'] ?? null,
                    'description' => $lineData['description'],
                    'product_notes' => $lineData['product_notes'] ?? null,
                    'quantity' => $lineData['quantity'] ?? 0,
                    'unit_price' => $lineData['unit_price'] ?? 0,
                    'tax_rate' => $lineData['tax_rate'] ?? 0,
                    'discount_amount' => $lineData['discount_amount'] ?? 0,
                ]);
            }
        }

        $quotation->refresh();
        $quotation->calculateTotals();

        return response()->json($quotation->fresh(['customer', 'lines.product']));
    }

    public function destroy($id)
    {
        $quotation = Quotation::find($id);

        if (! $quotation) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }

        if ($quotation->status !== 'draft') {
            return response()->json(['message' => 'Only draft quotations can be deleted'], 403);
        }

        $quotation->delete();

        return response()->json(['message' => 'Quotation deleted successfully']);
    }

    /**
     * Download the quotation as a PDF.
     */
    public function pdf($id)
    {
        $quotation = Quotation::with(['customer', 'lines.product'])->find($id);

        if (! $quotation) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }

        // Ensure totals are accurate before rendering.
        $quotation->calculateTotals();
        $quotation->refresh();

        $company = CompanyDataSource::forCurrentTenant();

        $pdf = Pdf::loadView('sales.quotation-pdf', [
            'quotation' => $quotation,
            'company' => $company,
        ])->setPaper('a4', 'portrait');

        $filename = 'quotation-'.$quotation->quotation_number.'.pdf';

        return $pdf->download($filename);
    }

    /**
     * Confirm a quotation.
     * Creates a SalesOrder from the quotation lines and marks the quotation as confirmed.
     */
    public function confirm($id)
    {
        $quotation = Quotation::with(['customer', 'lines.product'])->find($id);

        if (! $quotation) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }

        if ($quotation->status !== 'draft' && $quotation->status !== 'sent') {
            return response()->json(['message' => 'Only draft or sent quotations can be confirmed'], 403);
        }

        // Issue #180: config-driven approval gate. Quotation total must meet
        // or exceed the tenant's threshold to proceed; otherwise an
        // ApprovalRequest is created and the quotation stays in its current
        // status. The SalesOrder is NOT created until approval lands.
        if ($this->approvalGate->requiresApproval(Quotation::class, (float) $quotation->total)) {
            $approval = $this->approvalGate->createRequest(
                $quotation,
                Auth::user(),
                SalesApprovalGate::REQUEST_TYPE_QUOTATION,
                "Quotation total {$quotation->total} meets the tenant approval threshold."
            );

            return response()->json([
                'message' => 'Approval required',
                'requires_approval' => true,
                'approval_request' => $approval,
                'threshold' => $this->approvalGate->threshold(),
                'quotation' => $quotation->fresh(['customer', 'lines.product']),
            ], 202);
        }

        try {
            $quotation = DB::transaction(function () use ($quotation) {
                // Build SalesOrder lines from quotation lines
                $salesLines = $quotation->lines->map(fn ($line) => [
                    'line_type' => $line->line_type ?? 'product',
                    'sequence' => $line->sequence ?? 0,
                    'product_id' => $line->product_id,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'tax_rate' => $line->tax_rate,
                    'discount_amount' => $line->discount_amount,
                ])->toArray();

                // Resolve warehouse if needed
                $warehouseId = null;
                $productIds = array_filter(array_column($salesLines, 'product_id'));
                if (! empty($productIds)) {
                    $hasStockTracked = Product::whereIn('id', $productIds)
                        ->where('track_inventory', true)
                        ->exists();
                    if ($hasStockTracked) {
                        $warehouse = Warehouse::getDefault() ?? Warehouse::first();
                        $warehouseId = $warehouse?->id;
                    }
                }

                // Create SalesOrder
                $salesOrder = $this->salesOrderService->createOrder([
                    'customer_id' => $quotation->customer_id,
                    'channel' => 'admin',
                    'notes' => "Quotation #{$quotation->quotation_number}",
                    'lines' => $salesLines,
                    'warehouse_id' => $warehouseId,
                ]);

                // Confirm the SalesOrder
                $salesOrder = $this->salesOrderService->confirmOrder($salesOrder);

                // Link the SalesOrder to the quotation
                $quotation->update([
                    'status' => 'confirmed',
                    'sales_order_id' => $salesOrder->id,
                ]);

                return $quotation->fresh(['customer', 'lines.product', 'salesOrder']);
            });

            return response()->json([
                'message' => 'Quotation confirmed successfully',
                'data' => $quotation,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to confirm quotation',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create an invoice from a confirmed quotation.
     * Creates a new Invoice record linked back to the quotation.
     */
    public function createInvoice($id)
    {
        $quotation = Quotation::with(['customer', 'lines.product', 'salesOrder'])->find($id);

        if (! $quotation) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }

        if ($quotation->status !== 'confirmed') {
            return response()->json(['message' => 'Only confirmed quotations can be invoiced'], 403);
        }

        if (! $quotation->sales_order_id) {
            return response()->json(['message' => 'Quotation has no linked Sales Order'], 422);
        }

        try {
            $invoice = DB::transaction(function () use ($quotation) {
                // Allocate and fulfil the SalesOrder
                $salesOrder = $this->salesOrderService->allocateOrder($quotation->salesOrder);
                $salesOrder = $this->salesOrderService->fulfilOrder($salesOrder);

                // Create invoice from the SalesOrder
                $invoice = $this->salesOrderService->createInvoice($salesOrder);

                // Link the invoice back to the quotation
                $invoice->update([
                    'quotation_id' => $quotation->id,
                    'case_number' => $quotation->case_number,
                    'date_of_case' => $quotation->date_of_case,
                    'case_location' => $quotation->case_location,
                    'patient_name' => $quotation->patient_name,
                    'cost_center_id' => $quotation->cost_center_id,
                    'delivery_date' => $quotation->delivery_date,
                    'pr_no' => $quotation->pr_no,
                    'contract_no' => $quotation->contract_no,
                    'plant_code' => $quotation->plant_code,
                    'company_code' => $quotation->company_code,
                    'payment_terms_snapshot' => $quotation->credit_terms,
                ]);

                return $invoice->load(['customer', 'lines.product', 'quotation', 'salesOrder']);
            });

            return response()->json([
                'message' => 'Invoice created from quotation',
                'data' => $invoice,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create invoice from quotation',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk import quotations from CSV.
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
            'quote_no' => 'quotation_number',
            'quote_number' => 'quotation_number',
            'number' => 'quotation_number',
            'customer' => 'customer_name',
            'customer_code' => 'customer_code',
            'customer_name' => 'customer_name',
            'date' => 'quotation_date',
            'issue_date' => 'quotation_date',
            'valid_until' => 'valid_until',
            'expiry_date' => 'valid_until',
            'reference' => 'customer_reference',
            'po_number' => 'customer_reference',
            'invoice_no' => 'invoice_number',
            'inv_number' => 'invoice_number',
            'linked_invoice' => 'invoice_number',
            'sku' => 'product_sku',
            'product_sku' => 'product_sku',
            'item' => 'description',
            'item_description' => 'description',
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
            'email' => 'contact_email',
            'phone' => 'contact_phone',
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
                    $val = $data[$idx];
                    if (! mb_check_encoding($val, 'UTF-8')) {
                        $val = mb_convert_encoding($val, 'UTF-8', 'UTF-8');
                    }
                    $row[$key] = $val;
                }
            }
            if (! empty($row)) {
                $rows[] = $row;
            }
        }
        fclose($handle);

        if (count($rows) === 0) {
            return response()->json(['message' => 'No rows found'], 422);
        }

        // Group rows into quotations by quotation_number
        $groups = [];
        $order = [];
        foreach ($rows as $idx => $row) {
            $num = trim((string) ($row['quotation_number'] ?? ''));
            $key = $num !== '' ? 'num:'.$num : 'row:'.$idx;
            if (! isset($groups[$key])) {
                $groups[$key] = ['first_index' => $idx, 'header' => $row, 'lines' => []];
                $order[] = $key;
            }
            $groups[$key]['lines'][] = $row;
        }

        $created = 0;
        $errors = [];
        $conflictGroups = [];
        $customerCache = [];
        $productCache = [];

        foreach ($order as $key) {
            $group = $groups[$key];
            $firstIndex = $group['first_index'];
            $headerRow = $group['header'];

            // Detect conflicting customer codes within the same quotation group
            $customerCodes = [];
            foreach ($group['lines'] as $lineRow) {
                $code = trim((string) ($lineRow['customer_code'] ?? ''));
                $name = trim((string) ($lineRow['customer_name'] ?? ''));
                $identifier = $code ?: $name;
                if ($identifier !== '') {
                    $customerCodes[$identifier] = true;
                }
            }
            if (count($customerCodes) > 1) {
                $lineNumbers = [];
                foreach ($group['lines'] as $idx => $lineRow) {
                    $lineNumbers[] = $firstIndex + $idx + 2; // +2 for 1-indexed + header
                }
                $conflictGroups[] = [
                    'quotation_number' => $headerRow['quotation_number'] ?? '',
                    'customer_codes' => array_keys($customerCodes),
                    'line_numbers' => $lineNumbers,
                ];
                $errors[] = "Row {$lineNumbers[0]}: Conflicting customers for quotation_number '".($headerRow['quotation_number'] ?? '')."': ".implode(', ', array_keys($customerCodes)).'. Each quotation_number must belong to a single customer.';

                continue;
            }

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
                    $identifier = $code ?: $name;
                    throw new \RuntimeException(
                        "Customer not found: {$identifier}. ".
                        'Use an existing customer_code, customer_name, or customer_id. '.
                        'Check /customers to see available customers.'
                    );
                }

                $lines = [];
                foreach ($group['lines'] as $lineRow) {
                    $description = trim((string) ($lineRow['description'] ?? ''));
                    $quantity = (int) round($this->parseNumericValue($lineRow['quantity'] ?? null));
                    if ($quantity < 1) {
                        $quantity = 1;
                    }
                    $unitPrice = $this->parseNumericValue($lineRow['unit_price'] ?? null);
                    $taxRate = $this->parseNumericValue($lineRow['tax_rate'] ?? null);
                    $lineDiscount = $this->parseNumericValue($lineRow['line_discount_amount'] ?? null);

                    $line = [
                        'description' => $description !== '' ? $description : 'Imported line',
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
                            if ($unitPrice == 0) {
                                $line['unit_price'] = (float) $product->price;
                            }
                        }
                    }

                    $lines[] = $line;
                }

                if (count($lines) === 0) {
                    throw new \RuntimeException('Quotation has no lines');
                }

                $quotationDate = $this->parseImportDate($headerRow['quotation_date'] ?? null) ?? now()->toDateString();

                // Preserve the imported quotation number when provided (instead of
                // auto-generating one). Reject duplicates with a clear message.
                $importedNumber = trim((string) ($headerRow['quotation_number'] ?? ''));
                $createData = [
                    'quotation_date' => $quotationDate,
                    'valid_until' => $this->parseImportDate($headerRow['valid_until'] ?? null),
                    'customer_id' => $customer->id,
                    'customer_reference' => $headerRow['customer_reference'] ?? null,
                    'internal_reference' => $this->readImportValue($headerRow['internal_reference'] ?? null),
                    'credit_terms' => $this->readImportValue($headerRow['credit_terms'] ?? null),
                    'attention_to' => $this->readImportValue($headerRow['attention_to'] ?? null),
                    'contact_email' => $this->readImportValue($headerRow['contact_email'] ?? null),
                    'contact_phone' => $this->readImportValue($headerRow['contact_phone'] ?? null),
                    'salesperson' => $this->readImportValue($headerRow['salesperson'] ?? null),
                    'delivery_lead_time' => $this->readImportValue($headerRow['delivery_lead_time'] ?? null),
                    'shipping_method' => $this->readImportValue($headerRow['shipping_method'] ?? null),
                    'shipping_terms' => $this->readImportValue($headerRow['shipping_terms'] ?? null),
                    'validity' => $this->readImportValue($headerRow['validity'] ?? null),
                    'billing_address_snapshot' => $this->readImportValue($headerRow['billing_address_snapshot'] ?? null),
                    'shipping_address_snapshot' => $this->readImportValue($headerRow['shipping_address_snapshot'] ?? null),
                    'notes' => $headerRow['notes'] ?? null,
                    'discount_amount' => $this->parseNumericValue($headerRow['discount_amount'] ?? null),
                    'created_by' => Auth::id(),
                ];

                // Resolve billing/shipping contact names to IDs (auto-create if not found)
                $billingContactName = $this->readImportValue($headerRow['billing_contact_name'] ?? null);
                if ($billingContactName !== null) {
                    $createData['billing_contact_id'] = $this->resolveOrCreateContact($billingContactName, 'person', 'import_quotation');
                }
                $shippingContactName = $this->readImportValue($headerRow['shipping_contact_name'] ?? null);
                if ($shippingContactName !== null) {
                    $createData['shipping_contact_id'] = $this->resolveOrCreateContact($shippingContactName, 'person', 'import_quotation');
                }

                if ($importedNumber !== '') {
                    $existing = Quotation::withTrashed()->where('quotation_number', $importedNumber)->first();
                    if ($existing) {
                        // Nullify quotation_id on linked invoices before deleting
                        Invoice::withoutTrashed()->where('quotation_id', $existing->id)->update(['quotation_id' => null]);
                        $existing->lines()->delete();
                        $existing->forceDelete();
                    }
                    $createData['quotation_number'] = $importedNumber;
                }

                $quotation = Quotation::create($createData);

                foreach ($lines as $line) {
                    $quotation->lines()->create($line);
                }

                $quotation->refresh();
                $quotation->calculateTotals();

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
                    $medicalCase = $this->caseImportService->createCaseFromQuotationImport($quotation, $caseData);
                    if ($medicalCase) {
                        Log::info('Medical case created from quotation import', [
                            'quotation_id' => $quotation->id,
                            'case_id' => $medicalCase->id,
                            'case_no' => $medicalCase->case_no,
                        ]);
                    }
                } catch (\Throwable $caseError) {
                    Log::warning('Failed to create medical case from quotation import', [
                        'quotation_id' => $quotation->id,
                        'error' => $caseError->getMessage(),
                    ]);
                }

                // Link to existing invoice(s) if invoice_number is provided
                $invoiceNumberRaw = trim((string) ($headerRow['invoice_number'] ?? ''));
                if ($invoiceNumberRaw !== '') {
                    $invoiceNumbers = array_filter(array_map('trim', explode(',', $invoiceNumberRaw)));
                    $linkedCount = 0;
                    foreach ($invoiceNumbers as $invNum) {
                        $invoice = Invoice::where('invoice_number', $invNum)->first();
                        if ($invoice) {
                            $invoice->update(['quotation_id' => $quotation->id]);
                            $linkedCount++;
                        }
                    }
                    if ($linkedCount === 0) {
                        throw new \RuntimeException(
                            "Invoice number(s) {$invoiceNumberRaw} not found. The quotation was created but not linked to any invoice."
                        );
                    }
                }

                $created++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'row' => $firstIndex + 2,
                    'message' => 'Quotation '.trim((string) ($headerRow['quotation_number'] ?? ('row '.($firstIndex + 2)))).': '.$e->getMessage(),
                ];
            }
        }

        return response()->json([
            'created' => $created,
            'errors' => $errors,
            'conflict_groups' => $conflictGroups,
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

    private function parseNumericValue($value): float
    {
        if ($value === null || trim((string) $value) === '') {
            return 0;
        }
        $cleaned = preg_replace('/[^0-9.]/', '', trim((string) $value));

        return $cleaned !== '' ? (float) $cleaned : 0;
    }

    private function readImportValue($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return ($value === '' || $value === '-') ? null : $value;
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
        foreach (['Y-m-d', 'm/d/Y', 'n/j/y', 'n/j/Y', 'd-m-Y', 'Y/m/d'] as $format) {
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

    private function resolveOrCreateContact(string $name, string $type = 'person', string $source = 'import'): ?string
    {
        if (! mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        }

        $normalised = strtolower(trim($name));

        $tenantId = tenant()->id;
        $contact = \Modules\Contact\src\Models\CoreContact::where('tenant_id', $tenantId)->whereRaw('LOWER(display_name) = ?', [$normalised])->first()
            ?? \Modules\Contact\src\Models\CoreContact::where('tenant_id', $tenantId)->whereRaw('LOWER(first_name || \' \' || last_name) = ?', [$normalised])->first()
            ?? \Modules\Contact\src\Models\CoreContact::where('tenant_id', $tenantId)->whereRaw('LOWER(company_name) = ?', [$normalised])->first();

        if ($contact) {
            return $contact->id;
        }

        $parts = array_filter(explode(' ', trim($name)));
        $firstName = $parts ? array_shift($parts) : $name;
        $lastName = $parts ? implode(' ', $parts) : null;

        $newId = (string) Str::uuid();

        // Use raw SQL INSERT with schema-qualified table to bypass search_path issues.
        // DB::table() double-wraps quotes when table name contains dots — use DB::statement() instead.
        $schema = tenant()->getSchemaName();
        $table = $schema ? "\"{$schema}\".core_contacts" : 'core_contacts';
        $now = now()->toDateTimeString();

        DB::statement(
            "INSERT INTO {$table} (id, tenant_id, contact_type, first_name, last_name, display_name, company_name, source, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$newId, $tenantId, $type, $firstName, $lastName, trim($name), $lastName === null ? $name : null, $source, true, $now, $now]
        );

        return $newId;
    }

    /**
     * Cancel a quotation. Confirmed quotations cancel their generated Sales
     * Order in the same transaction when no downstream document exists.
     */
    public function cancel(\Illuminate\Http\Request $request, $id)
    {
        $quotation = Quotation::find($id);

        if (! $quotation) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }

        if (! in_array($quotation->status, ['draft', 'sent', 'confirmed'], true)) {
            return response()->json(['message' => 'Only draft, sent, or confirmed quotations can be cancelled'], 403);
        }

        if ($quotation->invoices()->exists() || $quotation->deliveryOrders()->exists()) {
            return response()->json([
                'message' => 'Cannot cancel this quotation because it has a linked invoice or delivery order.',
            ], 422);
        }

        $fromStatus = $quotation->status;

        $quotation = DB::transaction(function () use ($quotation) {
            if ($quotation->salesOrder && $quotation->salesOrder->status !== 'cancelled') {
                $this->salesOrderService->cancelOrder(
                    $quotation->salesOrder,
                    "Quotation {$quotation->quotation_number} cancelled"
                );
            }

            $quotation->update(['status' => 'cancelled']);

            return $quotation->fresh(['customer', 'lines.product', 'salesOrder']);
        });

        $this->logQuotationChatter(
            $quotation,
            "Cancelled quotation {$quotation->quotation_number}",
            [
                'from_state' => $fromStatus,
                'to_state' => 'cancelled',
            ],
        );

        return response()->json([
            'message' => 'Quotation cancelled',
            'data' => $quotation,
        ]);
    }

    /**
     * Reset a cancelled quotation back to draft.
     * Allows re-editing and re-confirming.
     */
    /**
     * Issue #178/#179: reject a quotation — voids the original and creates an
     * auto-versioned replacement draft (same correction group, version+1).
     */
    public function reject(Request $request, $id)
    {
        $quotation = Quotation::find($id);

        if (! $quotation) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }

        if ($quotation->status === 'cancelled') {
            return response()->json(['message' => 'Quotation is already cancelled/rejected'], 422);
        }

        $validated = $request->validate([
            'reason_code' => 'nullable|string|max:50',
            'reason_text' => 'nullable|string|max:500',
        ]);

        try {
            $replacement = app(\App\Services\ReplacementDraftService::class)->createQuotationReplacementDraft(
                $quotation,
                \App\Services\CorrectionRequest::fromRequest([
                    'reason_code' => $validated['reason_code'] ?? 'rejection',
                    'reason_text' => $validated['reason_text'] ?? 'Quotation rejected',
                    'user_id' => \Illuminate\Support\Facades\Auth::id(),
                ])
            );

            return response()->json([
                'message' => 'Quotation rejected; replacement draft created',
                'quotation' => $replacement->load('lines.product'),
                'superseded_quotation_id' => $quotation->id,
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to reject quotation',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function resetToDraft($id)
    {
        $quotation = Quotation::find($id);

        if (! $quotation) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }

        // Admin-only route (documents.reset_to_draft). Quotations carry no GL
        // or stock effects, so any non-draft status can simply return to draft.
        if ($quotation->status === 'draft') {
            return response()->json(['message' => 'Quotation is already in draft'], 422);
        }

        $fromStatus = $quotation->status;

        $quotation->update([
            'status' => 'draft',
            // A cancelled Sales Order remains as audit history. Reconfirming
            // the quotation must create and link a new active order.
            'sales_order_id' => null,
        ]);

        $this->logQuotationChatter(
            $quotation,
            "Reset quotation {$quotation->quotation_number} to draft",
            [
                'from_state' => $fromStatus,
                'to_state' => 'draft',
            ],
        );

        return response()->json([
            'message' => 'Quotation reset to draft',
            'data' => $quotation->fresh(['customer', 'lines.product']),
        ]);
    }

    public function bulkDestroy(\Illuminate\Http\Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $ids = $validated['ids'];
        $deletable = Quotation::whereIn('id', $ids)->whereIn('status', ['draft', 'sent', 'confirmed'])->get();
        $deletedIds = [];
        $skipped = [];

        foreach ($deletable as $quotation) {
            try {
                $quotation->delete();
                $deletedIds[] = $quotation->id;
            } catch (\Throwable $e) {
                $skipped[] = ['id' => $quotation->id, 'reason' => $e->getMessage()];
            }
        }

        $notFoundIds = array_values(array_diff($ids, $deletable->pluck('id')->toArray()));
        foreach ($notFoundIds as $notFoundId) {
            $skipped[] = ['id' => $notFoundId, 'reason' => 'Not found or not a draft/sent quotation'];
        }

        return response()->json([
            'message' => count($deletedIds).' quotation(s) deleted.',
            'deleted' => $deletedIds,
            'skipped' => $skipped,
        ]);
    }
}
