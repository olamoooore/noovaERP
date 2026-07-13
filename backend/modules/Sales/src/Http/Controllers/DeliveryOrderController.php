<?php

namespace Modules\Sales\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\DeliveryOrderService;

class DeliveryOrderController extends Controller
{
    public function __construct(
        private DeliveryOrderService $deliveryService,
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
