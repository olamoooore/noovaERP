<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\InvoiceService;
use App\Services\LockDateValidationService;
use Illuminate\Http\Request;
use Modules\Sales\Services\SalesOrderService;

class InvoiceController extends Controller
{
    public function __construct(
        private InvoiceService $invoiceService,
        private SalesOrderService $salesOrderService,
        private LockDateValidationService $lockDateValidator,
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
            'notes' => 'nullable|string',
            'terms' => 'nullable|string',
            'discount_amount' => 'nullable|numeric|min:0',
            'po_number' => 'nullable|string|max:100',
            'doctor_contact_id' => 'nullable|exists:core_contacts,id',
            'requestor_contact_id' => 'nullable|exists:core_contacts,id',
            'billing_contact_id' => 'nullable|exists:core_contacts,id',
            'shipping_contact_id' => 'nullable|exists:core_contacts,id',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'nullable|exists:products,id',
            'lines.*.description' => 'required|string',
            'lines.*.quantity' => 'required|integer|min:1',
            'lines.*.unit_price' => 'required|numeric|min:0',
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
    private function createInvoiceFromData(array $data): Invoice
    {
        // Preserve the imported invoice number when provided (reject duplicates).
        $importedNumber = isset($data['invoice_number']) ? trim((string) $data['invoice_number']) : '';
        if ($importedNumber !== '') {
            if (Invoice::where('invoice_number', $importedNumber)->exists()) {
                throw new \RuntimeException(
                    "Invoice number {$importedNumber} already exists. Use a unique number or leave it blank to auto-generate."
                );
            }
        }

        $salesLines = array_map(fn ($line) => [
            'product_id' => $line['product_id'] ?? null,
            'description' => $line['description'],
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
        $salesOrder = $this->salesOrderService->confirmOrder($salesOrder);
        $salesOrder = $this->salesOrderService->allocateOrder($salesOrder);
        $salesOrder = $this->salesOrderService->fulfilOrder($salesOrder);

        $invoiceOverrides = array_filter([
            'invoice_number' => $importedNumber !== '' ? $importedNumber : null,
            'invoice_date' => $data['invoice_date'] ?? null,
            'due_date' => $data['due_date'] ?? null,
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
            'doctor_contact_id' => $data['doctor_contact_id'] ?? null,
            'requestor_contact_id' => $data['requestor_contact_id'] ?? null,
            'billing_contact_id' => $data['billing_contact_id'] ?? null,
            'shipping_contact_id' => $data['shipping_contact_id'] ?? null,
        ], fn ($v) => $v !== null);

        return $this->salesOrderService->createInvoice(
            $salesOrder,
            empty($invoiceOverrides) ? null : $invoiceOverrides
        );
    }

    public function show($id)
    {
        $invoice = Invoice::with(['customer', 'lines.product', 'journalEntry', 'payments', 'billingContact', 'shippingContact', 'doctor', 'requestor'])->find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        return response()->json($invoice);
    }

    public function update(Request $request, $id)
    {
        $invoice = Invoice::find($id);

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
            'notes' => 'nullable|string',
            'terms' => 'nullable|string',
            'discount_amount' => 'nullable|numeric|min:0',
            'po_number' => 'nullable|string|max:100',
            'doctor_contact_id' => 'nullable|exists:core_contacts,id',
            'requestor_contact_id' => 'nullable|exists:core_contacts,id',
            'billing_contact_id' => 'nullable|exists:core_contacts,id',
            'shipping_contact_id' => 'nullable|exists:core_contacts,id',
        ]);

        $invoice->update($validated);
        $invoice->calculateTotals();

        return response()->json($invoice->fresh(['customer', 'lines', 'doctor', 'requestor', 'billingContact', 'shippingContact']));
    }

    public function send($id)
    {
        $invoice = Invoice::find($id);

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

    public function destroy($id)
    {
        $invoice = Invoice::find($id);

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
            'expiry_date' => 'due_date',
            'reference' => 'customer_reference',
            'po_number' => 'customer_reference',
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
            'company_reg_no' => 'company_registration_number',
            'tax_reg_no' => 'tax_registration_number',
            'email' => 'contact_email',
            'phone' => 'contact_phone',
        ];
        $header = array_map(fn ($key) => $columnAliases[$key] ?? $key, $header);

        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            if (! is_array($data) || count(array_filter($data, fn ($v) => (string) $v !== '')) === 0) {
                continue;
            }
            $row = [];
            foreach ($header as $idx => $key) {
                if ($key !== '') {
                    $row[$key] = $data[$idx] ?? null;
                }
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
                $customer = $this->resolveImportCustomer($headerRow, $customerCache);
                if (! $customer) {
                    $identifier = trim((string) ($headerRow['customer_code'] ?? $headerRow['customer_name'] ?? $headerRow['customer_id'] ?? ''));
                    throw new \RuntimeException("Customer not found: {$identifier}");
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
                ] as $field) {
                    $value = $this->readImportValue($headerRow[$field] ?? null);
                    if ($value !== null) {
                        $data[$field] = $field === 'discount_amount'
                            ? $this->parseNumericValue($value)
                            : $value;
                    }
                }

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
            $customer = Customer::whereRaw('LOWER(code) = ?', [strtolower($code)])->first();
        }
        if (! $customer && $name !== '' && $name !== '-') {
            $customer = Customer::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
        }
        if (! $customer && $id !== '' && filter_var($id, FILTER_VALIDATE_INT) !== false) {
            $customer = Customer::find((int) $id);
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
        $value = trim((string) $value);

        return ($value === '' || $value === '-') ? null : $value;
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
}
