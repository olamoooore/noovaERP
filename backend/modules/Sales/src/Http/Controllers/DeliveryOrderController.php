<?php

namespace Modules\Sales\Http\Controllers;

use App\Exports\DeliveryOrdersExport;
use App\Models\MedicalCase;
use App\Models\MedicalCaseItem;
use App\Platform\BulkDelete\BulkDeleteService;
use App\Platform\Import\ImportService;
use App\Services\CaseImportService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\DeliveryOrderService;

class DeliveryOrderController extends Controller
{
    public function __construct(
        private DeliveryOrderService $deliveryService,
        private BulkDeleteService $bulkDeleteService,
        private ImportService $importService,
        private CaseImportService $caseImportService,
    ) {}

    public function index(Request $request)
    {
        $query = $this->filteredDeliveryOrderQuery($request)
            ->with(['customer', 'salesOrder', 'lines.product', 'invoice', 'quotation']);

        return response()->json($query->paginate($request->per_page ?? 20));
    }

    public function export(Request $request)
    {
        $validated = $request->validate([
            'columns' => ['required', 'array', 'min:1', 'max:'.count(DeliveryOrdersExport::COLUMNS)],
            'columns.*' => ['required', 'string', 'distinct', 'in:'.implode(',', array_keys(DeliveryOrdersExport::COLUMNS))],
            'status' => ['nullable', 'string'],
            'type' => ['nullable', 'in:standalone,linked'],
            'customer_id' => ['nullable'],
            'sales_order_id' => ['nullable'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'search' => ['nullable', 'string', 'max:255'],
            'document_ids' => ['nullable', 'array', 'max:1000'],
            'document_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        return Excel::download(
            new DeliveryOrdersExport($this->filteredDeliveryOrderQuery($request), $validated['columns']),
            'delivery_orders_'.now()->format('Y-m-d').'.xlsx'
        );
    }

    private function filteredDeliveryOrderQuery(Request $request)
    {
        $query = DeliveryOrder::query()->orderBy('created_at', 'desc')->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $query->whereIn('status', explode(',', $request->status));
        }

        if ($request->filled('sales_order_id')) {
            $query->where('sales_order_id', $request->sales_order_id);
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('type')) {
            if ($request->type === 'standalone') {
                $query->whereNull('sales_order_id');
            } elseif ($request->type === 'linked') {
                $query->whereNotNull('sales_order_id');
            }
        }

        if ($request->filled('document_ids')) {
            $query->whereIn('id', $request->input('document_ids', []));
        }

        if ($request->filled('start_date')) {
            $query->whereDate('delivery_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('delivery_date', '<=', $request->end_date);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('delivery_order_number', 'ILIKE', "%{$search}%")
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('name', 'ILIKE', "%{$search}%");
                    })
                    ->orWhereHas('salesOrder', function ($sq) use ($search) {
                        $sq->where('sales_order_number', 'ILIKE', "%{$search}%");
                    });
            });
        }

        return $query;
    }

    public function store(Request $request)
    {
        $isStandalone = empty($request->sales_order_id);

        if ($isStandalone) {
            $validated = $request->validate([
                'customer_id' => 'required|exists:customers,id',
                'warehouse_id' => 'required|exists:warehouses,id',
                'delivery_date' => 'nullable|date',
                'expected_date' => 'nullable|date',
                'payment_terms' => 'nullable|string|max:255',
                'carrier' => 'nullable|string|max:100',
                'tracking_number' => 'nullable|string|max:100',
                'shipping_address' => 'nullable|string',
                'po_number' => 'nullable|string|max:100',
                'salesperson' => 'nullable|string|max:255',
                'attention_to' => 'nullable|string|max:255',
                'invoice_id' => 'nullable|exists:invoices,id',
                'quotation_id' => 'nullable|exists:quotations,id',
                'notes' => 'nullable|string',
                'lines' => 'required|array|min:1',
                'lines.*.line_type' => 'nullable|in:product,note,section',
                'lines.*.sequence' => 'nullable|integer|min:0',
                'lines.*.product_id' => 'nullable|exists:products,id',
                'lines.*.model_number' => 'nullable|string|max:100',
                'lines.*.description' => 'nullable|string',
                'lines.*.product_notes' => 'nullable|string',
                'lines.*.patient_name' => 'nullable|string|max:255',
                'lines.*.quantity_delivered' => 'nullable|numeric|min:0',
                'lines.*.quantity' => 'nullable|numeric|min:0',
                'lines.*.unit' => 'nullable|string|max:20',
                'lines.*.unit_price' => 'nullable|numeric|min:0',
                'lines.*.lot_number' => 'nullable|string|max:100',
                'lines.*.batch_number' => 'nullable|string|max:100',
                'lines.*.expiry_date' => 'nullable|date',
            ]);

            foreach ($validated['lines'] as $index => $line) {
                if (($line['line_type'] ?? 'product') === 'product' && (empty($line['product_id']) || (float) ($line['quantity_delivered'] ?? 0) <= 0)) {
                    return response()->json([
                        'message' => 'Product lines require a product and quantity greater than zero.',
                        'errors' => ["lines.{$index}" => ['Select a product and enter a delivery quantity.']],
                    ], 422);
                }
            }

            try {
                $deliveryOrder = $this->deliveryService->createStandaloneDeliveryOrder($validated);

                return response()->json([
                    'message' => 'Standalone delivery order created',
                    'data' => $deliveryOrder,
                ], 201);
            } catch (\Exception $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        // Linked to sales order
        $validated = $request->validate([
            'sales_order_id' => 'required|exists:sales_orders,id',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'delivery_date' => 'nullable|date',
            'expected_date' => 'nullable|date',
            'payment_terms' => 'nullable|string|max:255',
            'invoice_id' => 'nullable|exists:invoices,id',
            'quotation_id' => 'nullable|exists:quotations,id',
            'carrier' => 'nullable|string|max:100',
            'tracking_number' => 'nullable|string|max:100',
            'shipping_address' => 'nullable|string',
            'po_number' => 'nullable|string|max:100',
            'salesperson' => 'nullable|string|max:255',
            'attention_to' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.sales_order_line_id' => 'required|exists:sales_order_lines,id',
            'lines.*.quantity_delivered' => 'required|numeric|min:0.0001',
            'lines.*.description' => 'nullable|string',
            'lines.*.product_notes' => 'nullable|string',
            'lines.*.patient_name' => 'nullable|string|max:255',
            'lines.*.unit' => 'nullable|string|max:20',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.lot_number' => 'nullable|string|max:100',
            'lines.*.batch_number' => 'nullable|string|max:100',
            'lines.*.expiry_date' => 'nullable|date',
        ]);

        $order = SalesOrder::find($validated['sales_order_id']);
        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        try {
            $deliveryOrder = $this->deliveryService->createDeliveryOrder($order, $validated);

            return response()->json([
                'message' => 'Delivery order created',
                'data' => $deliveryOrder,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function show($id)
    {
        $deliveryOrder = DeliveryOrder::with([
            'customer',
            'salesOrder',
            'lines.product',
            'lines.salesOrderLine',
            'createdBy',
            'confirmedBy',
            'invoice',
            'quotation',
        ])->find($id);

        if (! $deliveryOrder) {
            return response()->json(['message' => 'Delivery order not found'], 404);
        }

        return response()->json($deliveryOrder);
    }

    public function update(Request $request, $id)
    {
        $deliveryOrder = DeliveryOrder::find($id);

        if (! $deliveryOrder) {
            return response()->json(['message' => 'Delivery order not found'], 404);
        }

        if (! $deliveryOrder->isDraft()) {
            return response()->json(['message' => 'Only draft delivery orders can be edited'], 422);
        }

        $validated = $request->validate([
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'delivery_date' => 'nullable|date',
            'expected_date' => 'nullable|date',
            'payment_terms' => 'nullable|string|max:255',
            'invoice_id' => 'nullable|exists:invoices,id',
            'quotation_id' => 'nullable|exists:quotations,id',
            'carrier' => 'nullable|string|max:100',
            'tracking_number' => 'nullable|string|max:100',
            'shipping_address' => 'nullable|string',
            'po_number' => 'nullable|string|max:100',
            'salesperson' => 'nullable|string|max:255',
            'attention_to' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'lines' => 'nullable|array',
            'lines.*.line_type' => 'nullable|in:product,note,section',
            'lines.*.sequence' => 'nullable|integer|min:0',
            'lines.*.product_id' => 'nullable|exists:products,id',
            'lines.*.model_number' => 'nullable|string|max:100',
            'lines.*.description' => 'nullable|string',
            'lines.*.product_notes' => 'nullable|string',
            'lines.*.patient_name' => 'nullable|string|max:255',
            'lines.*.quantity_delivered' => 'nullable|numeric|min:0.0001',
            'lines.*.unit' => 'nullable|string|max:20',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.lot_number' => 'nullable|string|max:100',
            'lines.*.expiry_date' => 'nullable|date',
        ]);

        DB::transaction(function () use ($deliveryOrder, $validated) {
            // Update header fields
            $headerFields = ['warehouse_id', 'delivery_date', 'expected_date', 'payment_terms', 'invoice_id', 'quotation_id', 'carrier', 'tracking_number', 'shipping_address', 'po_number', 'salesperson', 'attention_to', 'notes'];
            foreach ($headerFields as $field) {
                if (array_key_exists($field, $validated)) {
                    $deliveryOrder->{$field} = $validated[$field];
                }
            }
            $deliveryOrder->save();

            // Update lines if provided
            if (isset($validated['lines'])) {
                $deliveryOrder->lines()->delete();
                foreach ($validated['lines'] as $lineData) {
                    $qty = (float) ($lineData['quantity_delivered'] ?? 0);
                    $unitPrice = isset($lineData['unit_price']) ? (float) $lineData['unit_price'] : null;
                    \Modules\Sales\Models\DeliveryOrderLine::create([
                        'line_type' => $lineData['line_type'] ?? 'product',
                        'sequence' => $lineData['sequence'] ?? 0,
                        'delivery_order_id' => $deliveryOrder->id,
                        'product_id' => $lineData['product_id'] ?? null,
                        'model_number' => $lineData['model_number'] ?? null,
                        'description' => $lineData['description'] ?? null,
                        'product_notes' => $lineData['product_notes'] ?? null,
                        'patient_name' => $lineData['patient_name'] ?? null,
                        'quantity_ordered' => $qty,
                        'quantity_delivered' => $qty,
                        'unit' => $lineData['unit'] ?? 'pcs',
                        'unit_price' => $unitPrice,
                        'line_total' => $unitPrice ? $unitPrice * $qty : null,
                        'lot_number' => $lineData['lot_number'] ?? null,
                        'expiry_date' => $lineData['expiry_date'] ?? null,
                    ]);
                }

                // Recalculate total
                $total = $deliveryOrder->lines()->sum('line_total');
                if ($total > 0) {
                    $deliveryOrder->update(['total' => $total]);
                }
            }
        });

        return response()->json([
            'message' => 'Delivery order updated',
            'data' => $deliveryOrder->fresh(['lines.product', 'customer', 'invoice', 'quotation']),
        ]);
    }

    public function confirm($id)
    {
        $deliveryOrder = DeliveryOrder::find($id);

        if (! $deliveryOrder) {
            return response()->json(['message' => 'Delivery order not found'], 404);
        }

        try {
            $deliveryOrder = $this->deliveryService->confirmDeliveryOrder($deliveryOrder);

            return response()->json([
                'message' => 'Delivery order confirmed',
                'data' => $deliveryOrder,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Complete/validate a delivery order (CONFIRMED → DONE).
     * This is where stock is actually deducted.
     */
    public function complete($id)
    {
        $deliveryOrder = DeliveryOrder::find($id);

        if (! $deliveryOrder) {
            return response()->json(['message' => 'Delivery order not found'], 404);
        }

        try {
            $deliveryOrder = $this->deliveryService->completeDeliveryOrder($deliveryOrder);

            return response()->json([
                'message' => 'Delivery order completed — stock deducted',
                'data' => $deliveryOrder,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Reverse a completed delivery order (DONE → CANCELLED).
     * Posts compensating stock movements.
     */
    public function reverse(Request $request, $id)
    {
        $deliveryOrder = DeliveryOrder::find($id);

        if (! $deliveryOrder) {
            return response()->json(['message' => 'Delivery order not found'], 404);
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:3',
        ]);

        try {
            $deliveryOrder = $this->deliveryService->reverseDeliveryOrder($deliveryOrder, $validated['reason']);

            return response()->json([
                'message' => 'Delivery order reversed — stock restored',
                'data' => $deliveryOrder,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cancel(Request $request, $id)
    {
        $deliveryOrder = DeliveryOrder::find($id);

        if (! $deliveryOrder) {
            return response()->json(['message' => 'Delivery order not found'], 404);
        }

        $reason = $request->input('reason');

        try {
            $deliveryOrder = $this->deliveryService->cancelDeliveryOrder($deliveryOrder, $reason);

            return response()->json([
                'message' => 'Delivery order cancelled',
                'data' => $deliveryOrder,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Reset a cancelled delivery order back to draft.
     */
    public function resetToDraft($id)
    {
        $deliveryOrder = DeliveryOrder::find($id);

        if (! $deliveryOrder) {
            return response()->json(['message' => 'Delivery order not found'], 404);
        }

        try {
            $deliveryOrder = $this->deliveryService->resetToDraft($deliveryOrder);

            return response()->json([
                'message' => 'Delivery order reset to draft',
                'data' => $deliveryOrder,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy($id)
    {
        $deliveryOrder = DeliveryOrder::find($id);
        if (! $deliveryOrder) {
            return response()->json(['message' => 'Delivery order not found'], 404);
        }

        if ($deliveryOrder->status === 'confirmed') {
            return response()->json(['message' => 'Cannot delete a confirmed delivery order. Cancel it first.'], 422);
        }

        if ($deliveryOrder->status === 'done') {
            return response()->json(['message' => 'Cannot delete a completed delivery order. Reverse it first to restore stock.'], 422);
        }

        if ($deliveryOrder->status === 'cancelled') {
            return response()->json(['message' => 'Cannot delete a cancelled delivery order. Reset it to draft first if you need to edit, or use force-delete for cleanup.'], 422);
        }

        $deliveryOrder->delete();

        return response()->json(['message' => 'Delivery order deleted']);
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:delivery_orders,id',
        ]);

        // Block deletion of confirmed, done, and cancelled orders
        $blocked = DeliveryOrder::whereIn('id', $validated['ids'])
            ->whereIn('status', ['confirmed', 'done', 'cancelled'])
            ->count();

        if ($blocked > 0) {
            return response()->json([
                'message' => "Cannot delete {$blocked} confirmed/done/cancelled delivery order(s). Cancel them first.",
            ], 422);
        }

        $result = $this->bulkDeleteService->delete(
            'delivery_orders',
            $validated['ids'],
            tenant()?->id,
            ['soft_deletes' => true, 'check_fk' => false, 'user_id' => Auth::id()]
        );

        return response()->json([
            'message' => "{$result['deleted']} delivery order(s) deleted",
            'deleted' => $result['deleted'],
            'errors' => $result['errors'],
        ]);
    }

    /**
     * Import delivery orders from CSV.
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240',
        ]);

        $file = $request->file('file');
        $columnMap = [
            'delivery_order_number' => 'delivery_order_number',
            'customer_code' => 'customer_id',
            'customer_name' => 'customer_name',
            'delivery_date' => 'delivery_date',
            'expected_date' => 'expected_date',
            'carrier' => 'carrier',
            'tracking_number' => 'tracking_number',
            'shipping_address' => 'shipping_address',
            'attention_to' => 'attention_to',
            'po_number' => 'po_number',
            'salesperson' => 'salesperson',
            'invoice_number' => 'invoice_number',
            'quotation_number' => 'quotation_number',
            'notes' => 'notes',
            'case_no' => '_case_no',
            'case_tag' => '_case_tag',
            'case_date' => '_case_date',
            'patient_name' => 'patient_name',
            'delivered_to' => '_delivered_to',
            'other_note' => '_other_note',
        ];

        $result = $this->importService->importGroupedCSV(
            'delivery_orders',
            'delivery_order_lines',
            $file,
            $columnMap,
            [
                'tenant_id' => tenant()?->id,
                'user_id' => Auth::id(),
                'group_key' => 'delivery_order_number',
                'line_column_map' => [
                    'header_id' => 'delivery_order_id',
                    'map' => [
                        'product_sku' => 'product_id',
                        'description' => 'description',
                        'patient_name' => 'patient_name',
                        'quantity' => 'quantity_delivered',
                        'unit' => 'unit',
                        'unit_price' => 'unit_price',
                        'lot_number' => 'lot_number',
                        'expiry_date' => 'expiry_date',
                    ],
                ],
                'transform_header' => function (array $header, array $rows) {
                    // Resolve customer_id from code first, then name
                    if (! isset($header['customer_id']) || ! is_numeric($header['customer_id'])) {
                        $customer = null;

                        if (! empty($header['customer_id'])) {
                            $customer = \App\Models\Customer::where('code', $header['customer_id'])->first();
                        }

                        if (! $customer && ! empty($header['customer_name'])) {
                            $customer = \App\Models\Customer::where('name', $header['customer_name'])->first();
                        }

                        if (! $customer) {
                            return null;
                        }
                        $header['customer_id'] = $customer->id;
                    }

                    // Auto-fill shipping address from customer record
                    if (empty($header['shipping_address'])) {
                        $customer = \App\Models\Customer::find($header['customer_id']);
                        $header['shipping_address'] = $customer?->shipping_address
                            ?: $customer?->billing_address
                            ?: null;
                    }

                    unset($header['customer_name']);

                    // Resolve warehouse_id from the customer's default or first active warehouse
                    if (empty($header['warehouse_id'])) {
                        $warehouse = \App\Models\Warehouse::where('is_active', true)->first();
                        $header['warehouse_id'] = $warehouse?->id;
                    }

                    $header['status'] = 'draft';

                    // Parse delivery_date from CSV (support multiple formats)
                    if (! empty($header['delivery_date'])) {
                        try {
                            $header['delivery_date'] = \Carbon\Carbon::parse($header['delivery_date'])->format('Y-m-d');
                        } catch (\Exception $e) {
                            $header['delivery_date'] = now()->toDateString();
                        }
                    } else {
                        $header['delivery_date'] = now()->toDateString();
                    }

                    // Parse expected_date from CSV
                    if (! empty($header['expected_date'])) {
                        try {
                            $header['expected_date'] = \Carbon\Carbon::parse($header['expected_date'])->format('Y-m-d');
                        } catch (\Exception $e) {
                            unset($header['expected_date']);
                        }
                    }

                    // Resolve invoice_id from invoice_number (try multiple formats)
                    if (! empty($header['invoice_number'])) {
                        $invNum = $header['invoice_number'];
                        $invoice = \App\Models\Invoice::withoutTrashed()
                            ->where('invoice_number', $invNum)
                            ->first();

                        // Try slash format: 2024-09-027 → 2024/09/027
                        if (! $invoice && str_contains($invNum, '-')) {
                            $slash = str_replace('-', '/', $invNum);
                            $invoice = \App\Models\Invoice::withoutTrashed()
                                ->where('invoice_number', $slash)
                                ->first();
                        }

                        // Try INV- prefix: 2024/09/027 → INV-2024/09/027
                        if (! $invoice) {
                            $withPrefix = 'INV-'.$invNum;
                            $invoice = \App\Models\Invoice::withoutTrashed()
                                ->where('invoice_number', $withPrefix)
                                ->first();
                        }

                        $header['invoice_id'] = $invoice?->id;
                        unset($header['invoice_number']);
                    }

                    // Resolve quotation_id from quotation_number
                    if (! empty($header['quotation_number'])) {
                        $quotation = \App\Models\Quotation::withoutTrashed()
                            ->where('quotation_number', $header['quotation_number'])
                            ->first();
                        $header['quotation_id'] = $quotation?->id;
                        unset($header['quotation_number']);
                    }

                    // Calculate total from line items
                    $total = 0;
                    foreach ($rows as $row) {
                        $qty = (float) ($row['quantity_delivered'] ?? $row['quantity'] ?? 0);
                        $price = (float) ($row['unit_price'] ?? 0);
                        $total += $qty * $price;
                    }
                    if ($total > 0) {
                        $header['total'] = $total;
                    }

                    return $header;
                },
                'transform_line' => function (array $line, array $rawRow, int $idx) {
                    // Resolve product_id from SKU (always resolve, even if SKU is numeric)
                    if (isset($line['product_id']) && $line['product_id'] !== '') {
                        $product = \App\Models\Product::where('sku', $line['product_id'])->first();
                        if ($product) {
                            $line['product_id'] = $product->id;
                        }
                    }
                    $line['quantity_ordered'] = $line['quantity_delivered'] ?? 0;
                    $line['unit'] = $line['unit'] ?? 'pcs';

                    if (! empty($line['expiry_date'])) {
                        try {
                            $line['expiry_date'] = \Carbon\Carbon::parse($line['expiry_date'])->format('Y-m-d');
                        } catch (\Exception $e) {
                            unset($line['expiry_date']);
                        }
                    }

                    // Handle unit_price and calculate line_total
                    $unitPrice = isset($line['unit_price']) ? (float) $line['unit_price'] : 0;
                    $quantity = (float) ($line['quantity_delivered'] ?? 0);
                    if ($unitPrice > 0) {
                        $line['unit_price'] = $unitPrice;
                        $line['line_total'] = $unitPrice * $quantity;
                    }

                    return $line;
                },
            ]
        );

        // Post-import: create medical cases if case data is present
        $caseData = $this->extractCaseDataFromCsv($file);
        $caseCount = 0;
        foreach ($caseData as $doNumber => $data) {
            if (empty($data['case_no']) && empty($data['case_tag']) && empty($data['patient_name'])) {
                continue;
            }
            try {
                $do = DeliveryOrder::where('delivery_order_number', $doNumber)->first();
                if (! $do) {
                    continue;
                }

                $caseNo = $this->buildCaseNo($data['case_no'] ?? null, $doNumber);
                $bonusSchemeId = null;
                if (! empty($data['case_tag'])) {
                    $bonusSchemeId = $this->caseImportService->resolveOrCreateBonusScheme(
                        $data['case_tag'], $do->customer_id, $data['case_date'] ?? null
                    );
                }

                $isFoc = $this->caseImportService->isFocTag($data['case_tag'] ?? null);
                $case = MedicalCase::create([
                    'case_no' => $caseNo,
                    'case_tag' => $data['case_tag'] ?? null,
                    'customer_id' => $do->customer_id,
                    'delivery_address_id' => null,
                    'patient_reference' => $data['patient_name'] ?? null,
                    'case_date' => $data['case_date'] ?? null,
                    'delivered_to' => $data['delivered_to'] ?? null,
                    'notes' => $data['other_note'] ?? null,
                    'status' => 'delivered',
                    'created_by' => Auth::id(),
                ]);

                foreach ($do->lines as $line) {
                    MedicalCaseItem::create([
                        'medical_case_id' => $case->id,
                        'delivery_order_item_id' => $line->id,
                        'product_id' => $line->product_id,
                        'delivered_qty' => $line->quantity_delivered,
                        'allocated_qty' => $line->quantity_ordered,
                        'unit_price' => $isFoc ? 0 : $line->unit_price,
                        'is_foc' => $isFoc,
                    ]);
                }
                $caseCount++;
            } catch (\Throwable $e) {
                Log::warning('Failed to create medical case from DO import', [
                    'do_number' => $doNumber,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => "Import complete: {$result['created']} delivery order(s) created, {$caseCount} medical case(s) created",
            'created' => $result['created'],
            'cases_created' => $caseCount,
            'errors' => $result['errors'],
        ], ! empty($result['errors']) ? 207 : 201);
    }

    /**
     * Extract case data from CSV file, grouped by delivery_order_number.
     */
    private function extractCaseDataFromCsv($file): array
    {
        $caseData = [];
        $handle = fopen($file->getRealPath(), 'r');
        if (! $handle) {
            return [];
        }

        $headers = fgetcsv($handle);
        if (! $headers) {
            fclose($handle);

            return [];
        }
        $normalizedHeaders = array_map(fn ($h) => strtolower(trim(str_replace(' ', '_', $h))), $headers);
        $caseNoIdx = array_search('case_no', $normalizedHeaders);
        $caseTagIdx = array_search('case_tag', $normalizedHeaders);
        $caseDateIdx = array_search('case_date', $normalizedHeaders);
        $patientIdx = array_search('patient_name', $normalizedHeaders);
        $deliveredToIdx = array_search('delivered_to', $normalizedHeaders);
        $otherNoteIdx = array_search('other_note', $normalizedHeaders);
        $doNumberIdx = array_search('delivery_order_number', $normalizedHeaders);

        if ($doNumberIdx === false) {
            fclose($handle);

            return [];
        }

        while (($row = fgetcsv($handle)) !== false) {
            if (empty(array_filter($row))) {
                continue;
            }
            $doNumber = trim((string) ($row[$doNumberIdx] ?? ''));
            if ($doNumber === '') {
                continue;
            }

            $caseData[$doNumber] = [
                'case_no' => $caseNoIdx !== false ? trim((string) ($row[$caseNoIdx] ?? '')) : '',
                'case_tag' => $caseTagIdx !== false ? trim((string) ($row[$caseTagIdx] ?? '')) : '',
                'case_date' => $caseDateIdx !== false ? $this->parseDateForCase($row[$caseDateIdx] ?? null) : null,
                'patient_name' => $patientIdx !== false ? trim((string) ($row[$patientIdx] ?? '')) : '',
                'delivered_to' => $deliveredToIdx !== false ? trim((string) ($row[$deliveredToIdx] ?? '')) : '',
                'other_note' => $otherNoteIdx !== false ? trim((string) ($row[$otherNoteIdx] ?? '')) : '',
            ];
        }
        fclose($handle);

        return $caseData;
    }

    private function parseDateForCase(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($value)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    private function buildCaseNo(?string $caseNo, string $doNumber): string
    {
        if ($caseNo && trim($caseNo) !== '') {
            $base = 'DO-'.trim($doNumber).'-'.trim($caseNo);
            $final = $base;
            $counter = 1;
            while (MedicalCase::withTrashed()->where('case_no', $final)->exists()) {
                $final = $base.'-'.$counter;
                $counter++;
                if ($counter > 100) {
                    return 'DO-CASE-'.$doNumber;
                }
            }

            return $final;
        }

        return 'DO-CASE-'.$doNumber;
    }

    /**
     * Download import template.
     */
    public function template()
    {
        $columns = [
            ['name' => 'delivery_order_number', 'example' => 'DO2024/09/002', 'description' => 'Groups rows. Blank = auto-generated.'],
            ['name' => 'customer_code', 'example' => 'VC0028', 'description' => 'Customer code (required).'],
            ['name' => 'customer_name', 'example' => 'Dr Daniel Koh', 'description' => 'Customer name (alternative to code).'],
            ['name' => 'delivery_date', 'example' => '10/9/2024', 'description' => 'Delivery date. Default: today.'],
            ['name' => 'expected_date', 'example' => '', 'description' => 'Expected delivery date (optional).'],
            ['name' => 'carrier', 'example' => '', 'description' => 'Carrier name (optional).'],
            ['name' => 'tracking_number', 'example' => '', 'description' => 'Tracking number (optional).'],
            ['name' => 'po_number', 'example' => 'PO-2024-001', 'description' => 'Purchase order number.'],
            ['name' => 'salesperson', 'example' => 'John Smith', 'description' => 'Salesperson name.'],
            ['name' => 'invoice_number', 'example' => 'INV-2024-001', 'description' => 'Linked invoice number.'],
            ['name' => 'quotation_number', 'example' => 'QT-2024-001', 'description' => 'Linked quotation number.'],
            ['name' => 'case_no', 'example' => '1st', 'description' => 'Medical case number (e.g. 1st, 2nd, 3rd).'],
            ['name' => 'case_tag', 'example' => 'FOC', 'description' => 'Case tag: FOC, GEH, HTx 60s, Clinic, CCK — auto-creates bonus scheme.'],
            ['name' => 'case_date', 'example' => '5/15/2025', 'description' => 'Date of the medical case.'],
            ['name' => 'patient_name', 'example' => 'Jane Doe', 'description' => 'Patient name for this line item.'],
            ['name' => 'delivered_to', 'example' => 'OT, Clinic, MENH', 'description' => 'Where it was delivered to.'],
            ['name' => 'other_note', 'example' => 'Clinic', 'description' => 'Other case notes.'],
            ['name' => 'product_sku', 'example' => '21007', 'description' => 'Product SKU (links to product).'],
            ['name' => 'description', 'example' => 'HTx60s Disposable Hysteroscope', 'description' => 'Line description.'],
            ['name' => 'quantity', 'example' => '6', 'description' => 'Quantity to deliver.'],
            ['name' => 'unit', 'example' => 'pcs', 'description' => 'Unit of measure. Default: pcs.'],
            ['name' => 'unit_price', 'example' => '50.00', 'description' => 'Unit price per item.'],
            ['name' => 'lot_number', 'example' => 'LOT-2026-001', 'description' => 'Lot or batch number.'],
            ['name' => 'expiry_date', 'example' => '2027-07-15', 'description' => 'Lot expiry date.'],
        ];

        return $this->importService->downloadTemplate($columns, 'delivery-order-import-template.csv');
    }

    /**
     * Get delivery status summary for a sales order.
     */
    public function forSalesOrder($salesOrderId)
    {
        $order = SalesOrder::with(['lines.product', 'deliveryOrders.lines'])->find($salesOrderId);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        $summary = $order->lines->map(function ($line) {
            $totalDelivered = $line->deliveryLines
                ->filter(fn ($dl) => $dl->deliveryOrder->status === 'done')
                ->sum('quantity_delivered');

            return [
                'sales_order_line_id' => $line->id,
                'product_id' => $line->product_id,
                'product_name' => $line->product?->name,
                'description' => $line->description,
                'quantity_ordered' => (float) $line->quantity,
                'quantity_delivered' => $totalDelivered,
                'quantity_remaining' => (float) $line->quantity - $totalDelivered,
            ];
        });

        return response()->json([
            'sales_order' => $order->only(['id', 'sales_order_number', 'status']),
            'lines' => $summary,
            'delivery_orders' => $order->deliveryOrders->map(fn ($do) => [
                'id' => $do->id,
                'delivery_order_number' => $do->delivery_order_number,
                'status' => $do->status,
                'delivery_date' => $do->delivery_date?->format('Y-m-d'),
                'total_lines' => $do->lines->count(),
            ]),
        ]);
    }
}
