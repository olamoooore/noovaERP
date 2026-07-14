<?php

namespace Modules\Sales\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\DeliveryOrderService;
use App\Platform\BulkDelete\BulkDeleteService;
use App\Platform\Import\ImportService;

class DeliveryOrderController extends Controller
{
    public function __construct(
        private DeliveryOrderService $deliveryService,
        private BulkDeleteService $bulkDeleteService,
        private ImportService $importService,
    ) {}

    public function index(Request $request)
    {
        $query = DeliveryOrder::with(['customer', 'salesOrder', 'lines.product'])
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
                'warehouse_id' => 'nullable|exists:warehouses,id',
                'delivery_date' => 'nullable|date',
                'expected_date' => 'nullable|date',
                'carrier' => 'nullable|string|max:100',
                'tracking_number' => 'nullable|string|max:100',
                'shipping_address' => 'nullable|string',
                'notes' => 'nullable|string',
                'lines' => 'required|array|min:1',
                'lines.*.product_id' => 'nullable|exists:products,id',
                'lines.*.description' => 'nullable|string',
                'lines.*.quantity_delivered' => 'required|numeric|min:0.0001',
                'lines.*.quantity' => 'nullable|numeric|min:0.0001',
                'lines.*.unit' => 'nullable|string|max:20',
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
            'delivery_date' => 'nullable|date',
            'expected_date' => 'nullable|date',
            'carrier' => 'nullable|string|max:100',
            'tracking_number' => 'nullable|string|max:100',
            'shipping_address' => 'nullable|string',
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.sales_order_line_id' => 'required|exists:sales_order_lines,id',
            'lines.*.quantity_delivered' => 'required|numeric|min:0.0001',
            'lines.*.description' => 'nullable|string',
            'lines.*.unit' => 'nullable|string|max:20',
        ]);

        $order = SalesOrder::find($validated['sales_order_id']);
        if (!$order) {
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
        ])->find($id);

        if (!$deliveryOrder) {
            return response()->json(['message' => 'Delivery order not found'], 404);
        }

        return response()->json($deliveryOrder);
    }

    public function confirm($id)
    {
        $deliveryOrder = DeliveryOrder::find($id);

        if (!$deliveryOrder) {
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

    public function cancel($id)
    {
        $deliveryOrder = DeliveryOrder::find($id);

        if (!$deliveryOrder) {
            return response()->json(['message' => 'Delivery order not found'], 404);
        }

        try {
            $deliveryOrder = $this->deliveryService->cancelDeliveryOrder($deliveryOrder);
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
        if (!$deliveryOrder) {
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
            'customer_code' => 'customer_id',
            'customer_name' => 'customer_name',
            'delivery_date' => 'delivery_date',
            'expected_date' => 'expected_date',
            'carrier' => 'carrier',
            'tracking_number' => 'tracking_number',
            'shipping_address' => 'shipping_address',
            'notes' => 'notes',
            'product_sku' => 'product_sku',
            'description' => 'description',
            'quantity' => 'quantity_delivered',
            'unit' => 'unit',
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
                    ],
                ],
                'transform_header' => function (array $header, array $rows) {
                    // Resolve customer_id from code or name
                    if (isset($header['customer_name']) && !isset($header['customer_id'])) {
                        $customer = \App\Models\Customer::where('name', $header['customer_name'])->first();
                        if (!$customer) {
                            return null; // Skip group if customer not found
                        }
                        $header['customer_id'] = $customer->id;
                    }
                    unset($header['customer_name']);
                    $header['status'] = 'draft';
                    $header['delivery_date'] = $header['delivery_date'] ?? now()->toDateString();
                    return $header;
                },
                'transform_line' => function (array $line, array $rawRow, int $idx) {
                    // Resolve product_id from SKU
                    if (isset($line['product_id']) && !is_numeric($line['product_id'])) {
                        $product = \App\Models\Product::where('sku', $line['product_id'])->first();
                        $line['product_id'] = $product?->id;
                    }
                    $line['quantity_ordered'] = $line['quantity_delivered'] ?? 0;
                    $line['unit'] = $line['unit'] ?? 'pcs';
                    return $line;
                },
            ]
        );

        return response()->json([
            'message' => "Import complete: {$result['created']} delivery order(s) created",
            'created' => $result['created'],
            'errors' => $result['errors'],
        ], !empty($result['errors']) ? 207 : 201);
    }

    /**
     * Download import template.
     */
    public function template()
    {
        $columns = [
            ['name' => 'delivery_order_number', 'example' => 'DO-2026-0001', 'description' => 'Groups rows. Blank = auto-generated.'],
            ['name' => 'customer_code', 'example' => 'CUST-001', 'description' => 'Customer code (required).'],
            ['name' => 'customer_name', 'example' => 'Acme Corp', 'description' => 'Alternative to customer_code.'],
            ['name' => 'delivery_date', 'example' => '2026-07-14', 'description' => 'Delivery date. Default: today.'],
            ['name' => 'expected_date', 'example' => '2026-07-15', 'description' => 'Expected delivery date.'],
            ['name' => 'carrier', 'example' => 'DHL', 'description' => 'Carrier name.'],
            ['name' => 'tracking_number', 'example' => 'DHL123456', 'description' => 'Tracking number.'],
            ['name' => 'product_sku', 'example' => 'SKU-001', 'description' => 'Product SKU (links to product).'],
            ['name' => 'description', 'example' => 'Widget A', 'description' => 'Line description.'],
            ['name' => 'quantity', 'example' => '10', 'description' => 'Quantity to deliver.'],
            ['name' => 'unit', 'example' => 'pcs', 'description' => 'Unit of measure. Default: pcs.'],
        ];

        return $this->importService->downloadTemplate($columns, 'delivery-order-import-template.csv');
    }

    /**
     * Get delivery status summary for a sales order.
     */
    public function forSalesOrder($salesOrderId)
    {
        $order = SalesOrder::with(['lines.product', 'deliveryOrders.lines'])->find($salesOrderId);

        if (!$order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        $summary = $order->lines->map(function ($line) {
            $totalDelivered = $line->deliveryLines
                ->filter(fn ($dl) => $dl->deliveryOrder->status === 'confirmed')
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
