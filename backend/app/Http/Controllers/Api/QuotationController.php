<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Warehouse;
use App\Services\InvoiceService;
use App\Services\LockDateValidationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Services\SalesOrderService;
use Modules\TemplateBuilder\Services\DataSource\CompanyDataSource;

class QuotationController extends Controller
{
    public function __construct(
        private SalesOrderService $salesOrderService,
        private InvoiceService $invoiceService,
        private LockDateValidationService $lockDateValidator,
    ) {}

    public function index(Request $request)
    {
        $query = Quotation::with(['customer', 'lines'])
            ->orderBy('quotation_date', 'desc');

        if ($request->has('status')) {
            $statuses = explode(',', $request->status);
            $query->whereIn('status', $statuses);
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('quotation_number', 'ILIKE', "%{$search}%")
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('name', 'ILIKE', "%{$search}%");
                    });
            });
        }

        $quotations = $query->paginate($request->per_page ?? 20);

        return response()->json($quotations);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'quotation_date' => 'nullable|date',
            'valid_until' => 'nullable|date',
            'customer_reference' => 'nullable|string|max:100',
            'internal_reference' => 'nullable|string|max:100',
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
            'billing_address_snapshot' => 'nullable|string',
            'shipping_address_snapshot' => 'nullable|string',
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
            $quotation = DB::transaction(function () use ($validated) {
                $data = array_filter([
                    'quotation_date' => $validated['quotation_date'] ?? now()->toDateString(),
                    'valid_until' => $validated['valid_until'] ?? null,
                    'customer_id' => $validated['customer_id'],
                    'customer_reference' => $validated['customer_reference'] ?? null,
                    'internal_reference' => $validated['internal_reference'] ?? null,
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
                    'billing_address_snapshot' => $validated['billing_address_snapshot'] ?? null,
                    'shipping_address_snapshot' => $validated['shipping_address_snapshot'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'terms' => $validated['terms'] ?? null,
                    'discount_amount' => $validated['discount_amount'] ?? null,
                    'created_by' => Auth::id(),
                ], fn ($v) => $v !== null);

                $quotation = Quotation::create($data);

                foreach ($validated['lines'] as $lineData) {
                    $quotation->lines()->create([
                        'product_id' => $lineData['product_id'] ?? null,
                        'description' => $lineData['description'],
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
        $quotation = Quotation::with(['customer', 'lines.product', 'salesOrder', 'invoices', 'billingContact', 'shippingContact'])->find($id);

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

        $validated = $request->validate([
            'customer_id' => 'exists:customers,id',
            'quotation_date' => 'date',
            'valid_until' => 'nullable|date',
            'customer_reference' => 'nullable|string|max:100',
            'internal_reference' => 'nullable|string|max:100',
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
            'billing_address_snapshot' => 'nullable|string',
            'shipping_address_snapshot' => 'nullable|string',
            'notes' => 'nullable|string',
            'terms' => 'nullable|string',
            'discount_amount' => 'nullable|numeric|min:0',
        ]);

        $quotation->update($validated);

        if ($request->has('lines')) {
            $quotation->lines()->delete();
            foreach ($request->lines as $lineData) {
                $quotation->lines()->create([
                    'product_id' => $lineData['product_id'] ?? null,
                    'description' => $lineData['description'],
                    'quantity' => $lineData['quantity'],
                    'unit_price' => $lineData['unit_price'],
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

        try {
            $quotation = DB::transaction(function () use ($quotation) {
                // Build SalesOrder lines from quotation lines
                $salesLines = $quotation->lines->map(fn ($line) => [
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
                $invoice->update(['quotation_id' => $quotation->id]);

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
        $customerCache = [];
        $productCache = [];

        foreach ($order as $key) {
            $group = $groups[$key];
            $firstIndex = $group['first_index'];
            $headerRow = $group['header'];

            try {
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
                    if (Quotation::withoutTrashed()->where('quotation_number', $importedNumber)->exists()) {
                        throw new \RuntimeException(
                            "Quotation number {$importedNumber} already exists. Use a unique number or leave it blank to auto-generate."
                        );
                    }
                    $createData['quotation_number'] = $importedNumber;
                }

                $quotation = Quotation::create($createData);

                foreach ($lines as $line) {
                    $quotation->lines()->create($line);
                }

                $quotation->refresh();
                $quotation->calculateTotals();

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
        $normalised = strtolower(trim($name));

        $contact = \Modules\Contact\src\Models\CoreContact::whereRaw('LOWER(display_name) = ?', [$normalised])->first()
            ?? \Modules\Contact\src\Models\CoreContact::whereRaw('LOWER(first_name || \' \' || last_name) = ?', [$normalised])->first()
            ?? \Modules\Contact\src\Models\CoreContact::whereRaw('LOWER(company_name) = ?', [$normalised])->first();

        if ($contact) {
            return $contact->id;
        }

        $parts = array_filter(explode(' ', trim($name)));
        $firstName = $parts ? array_shift($parts) : $name;
        $lastName = $parts ? implode(' ', $parts) : null;

        $newContact = \Modules\Contact\src\Models\CoreContact::create([
            'tenant_id'    => tenant()->id,
            'contact_type' => $type,
            'first_name'   => $firstName,
            'last_name'    => $lastName,
            'display_name' => trim($name),
            'company_name' => $lastName === null ? $name : null,
            'source'       => $source,
            'is_active'    => true,
        ]);

        return $newContact->id;
    }

    public function bulkDestroy(\Illuminate\Http\Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $ids = $validated['ids'];
        $deletable = Quotation::whereIn('id', $ids)->whereIn('status', ['draft', 'sent'])->get();
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
            'message' => count($deletedIds) . ' quotation(s) deleted.',
            'deleted' => $deletedIds,
            'skipped' => $skipped,
        ]);
    }
}
