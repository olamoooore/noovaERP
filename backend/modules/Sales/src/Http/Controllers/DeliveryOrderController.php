<?php

namespace Modules\Sales\Http\Controllers;

use App\Platform\BulkDelete\BulkDeleteService;
use App\Platform\Import\ImportService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\DeliveryOrderService;

class DeliveryOrderController extends Controller
{
    public function __construct(
        private DeliveryOrderService $deliveryService,
        private BulkDeleteService $bulkDeleteService,
        private ImportService $importService,
    ) {}

    public function index(Request $request)
    {
        $query = DeliveryOrder::with(['customer', 'salesOrder', 'lines.product', 'invoice', 'quotation'])
            ->orderBy('created_at', 'desc');

        if ($request->has('status')) {
            $query->whereIn('status', explode(',', $request->status));
        }

        if ($request->has('sales_order_id')) {
            $query->where('sales_order_id', $request->sales_order_id);
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->has('type')) {
            if ($request->type === 'standalone') {
                $query->whereNull('sales_order_id');
            } elseif ($request->type === 'linked') {
                $query->whereNotNull('sales_order_id');
            }
        }

        if ($request->has('search')) {
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

        return response()->json(
            $query->paginate($request->per_page ?? 20)
        );
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
                'lines.*.product_id' => 'nullable|exists:products,id',
                'lines.*.description' => 'nullable|string',
                'lines.*.quantity_delivered' => 'required|numeric|min:0.0001',
                'lines.*.quantity' => 'nullable|numeric|min:0.0001',
                'lines.*.unit' => 'nullable|string|max:20',
                'lines.*.unit_price' => 'nullable|numeric|min:0',
                'lines.*.lot_number' => 'nullable|string|max:100',
                'lines.*.batch_number' => 'nullable|string|max:100',
                'lines.*.expiry_date' => 'nullable|date',
            ]);

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
            'lines.*.sales_order_line_id' => 'required|exists:sales_order_lines,id',
            'lines.*.quantity_delivered' => 'required|numeric|min:0.0001',
            'lines.*.description' => 'nullable|string',
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
            'delivery_date' => 'nullable|date',
            'expected_date' => 'nullable|date',
            'carrier' => 'nullable|string|max:100',
            'tracking_number' => 'nullable|string|max:100',
            'shipping_address' => 'nullable|string',
            'po_number' => 'nullable|string|max:100',
            'salesperson' => 'nullable|string|max:255',
            'attention_to' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'lines' => 'nullable|array',
            'lines.*.product_id' => 'nullable|exists:products,id',
            'lines.*.description' => 'nullable|string',
            'lines.*.quantity_delivered' => 'nullable|numeric|min:0.0001',
            'lines.*.unit' => 'nullable|string|max:20',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.lot_number' => 'nullable|string|max:100',
        ]);

        DB::transaction(function () use ($deliveryOrder, $validated) {
            // Update header fields
            $headerFields = ['delivery_date', 'expected_date', 'carrier', 'tracking_number', 'shipping_address', 'po_number', 'salesperson', 'attention_to', 'notes'];
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
                        'delivery_order_id' => $deliveryOrder->id,
                        'product_id' => $lineData['product_id'] ?? null,
                        'description' => $lineData['description'] ?? null,
                        'quantity_ordered' => $qty,
                        'quantity_delivered' => $qty,
                        'unit' => $lineData['unit'] ?? 'pcs',
                        'unit_price' => $unitPrice,
                        'line_total' => $unitPrice ? $unitPrice * $qty : null,
                        'lot_number' => $lineData['lot_number'] ?? null,
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

    public function destroy($id)
    {
        $deliveryOrder = DeliveryOrder::find($id);
        if (! $deliveryOrder) {
            return response()->json(['message' => 'Delivery order not found'], 404);
        }

        if ($deliveryOrder->status === 'confirmed') {
            return response()->json(['message' => 'Cannot delete a confirmed delivery order. Cancel it first.'], 422);
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

        // Block deletion of confirmed orders
        $confirmed = DeliveryOrder::whereIn('id', $validated['ids'])
            ->where('status', 'confirmed')
            ->count();

        if ($confirmed > 0) {
            return response()->json([
                'message' => "Cannot delete {$confirmed} confirmed delivery order(s). Cancel them first.",
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
                        'quantity' => 'quantity_delivered',
                        'unit' => 'unit',
                        'unit_price' => 'unit_price',
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

                    // Resolve invoice_id from invoice_number
                    if (! empty($header['invoice_number'])) {
                        $invoice = \App\Models\Invoice::withoutTrashed()
                            ->where('invoice_number', $header['invoice_number'])
                            ->first();
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

        return response()->json([
            'message' => "Import complete: {$result['created']} delivery order(s) created",
            'created' => $result['created'],
            'errors' => $result['errors'],
        ], ! empty($result['errors']) ? 207 : 201);
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
            ['name' => 'product_sku', 'example' => '21007', 'description' => 'Product SKU (links to product).'],
            ['name' => 'description', 'example' => 'HTx60s Disposable Hysteroscope', 'description' => 'Line description.'],
            ['name' => 'quantity', 'example' => '6', 'description' => 'Quantity to deliver.'],
            ['name' => 'unit', 'example' => 'pcs', 'description' => 'Unit of measure. Default: pcs.'],
            ['name' => 'unit_price', 'example' => '50.00', 'description' => 'Unit price per item.'],
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
