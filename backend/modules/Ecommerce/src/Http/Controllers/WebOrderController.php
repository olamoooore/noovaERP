<?php

namespace Modules\Ecommerce\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Ecommerce\Models\WebOrder;

class WebOrderController extends Controller
{
    /**
     * Get order details for guest (by token)
     */
    public function showGuest(string $token): JsonResponse
    {
        $order = WebOrder::with('items')
            ->where('guest_token', $token)
            ->first();

        if (!$order) {
            return response()->json([
                'error' => 'Order not found',
            ], 404);
        }

        return response()->json([
            'order' => $this->formatOrderResponse($order),
        ]);
    }

    /**
     * Get order by ID (for authenticated customers)
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $customer = $request->attributes->get('web_customer');

        if (!$customer) {
            return response()->json([
                'error' => 'Unauthorized',
            ], 401);
        }

        $order = WebOrder::with('items', 'invoice')
            ->where('id', $id)
            ->where('customer_id', $customer->id)
            ->first();

        if (!$order) {
            return response()->json([
                'error' => 'Order not found',
            ], 404);
        }

        return response()->json([
            'order' => $this->formatOrderResponse($order),
        ]);
    }

    /**
     * List orders for authenticated customer
     */
    public function index(Request $request): JsonResponse
    {
        $customer = $request->attributes->get('web_customer');

        if (!$customer) {
            return response()->json([
                'error' => 'Unauthorized',
            ], 401);
        }

        $orders = WebOrder::with('items')
            ->where('customer_id', $customer->id)
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 10));

        return response()->json([
            'orders' => $orders->map(fn($order) => $this->formatOrderResponse($order)),
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'total' => $orders->total(),
                'per_page' => $orders->perPage(),
            ],
        ]);
    }

    /**
     * Admin: List all web orders with filters
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $query = WebOrder::with('items', 'customer');

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filter by payment status
        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->input('payment_status'));
        }

        // Search by order number, email, or name
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        // Date range
        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->input('from_date'));
        }
        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->input('to_date'));
        }

        $orders = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'orders' => $orders->map(fn($order) => $this->formatOrderResponse($order)),
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'total' => $orders->total(),
                'per_page' => $orders->perPage(),
                'last_page' => $orders->lastPage(),
            ],
            'stats' => [
                'total_orders' => WebOrder::count(),
                'pending' => WebOrder::where('status', 'pending')->count(),
                'confirmed' => WebOrder::where('status', 'confirmed')->count(),
                'shipped' => WebOrder::where('status', 'shipped')->count(),
                'total_revenue' => (float) WebOrder::where('payment_status', 'paid')->sum('total'),
            ],
        ]);
    }

    /**
     * Admin: View single order detail
     */
    public function adminShow(int $id): JsonResponse
    {
        $order = WebOrder::with('items', 'customer', 'invoice')
            ->find($id);

        if (!$order) {
            return response()->json([
                'error' => 'Order not found',
            ], 404);
        }

        $response = $this->formatOrderResponse($order);
        $response['customer'] = $order->customer ? [
            'id' => $order->customer->id,
            'code' => $order->customer->code,
            'name' => $order->customer->name,
            'email' => $order->customer->email,
            'phone' => $order->customer->phone,
            'total_orders' => $order->customer->total_orders,
            'total_purchases' => (float) $order->customer->total_purchases,
        ] : null;
        $response['invoice'] = $order->invoice ? [
            'id' => $order->invoice->id,
            'invoice_number' => $order->invoice->invoice_number,
            'status' => $order->invoice->status,
        ] : null;

        return response()->json([
            'order' => $response,
        ]);
    }

    /**
     * Admin: Update order status
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:pending,confirmed,processing,shipped,delivered,cancelled',
        ]);

        $order = WebOrder::find($id);

        if (!$order) {
            return response()->json([
                'error' => 'Order not found',
            ], 404);
        }

        $oldStatus = $order->status;
        $newStatus = $validated['status'];

        $updateData = ['status' => $newStatus];

        // Set timestamps based on status change
        if ($newStatus === 'confirmed' && !$order->confirmed_at) {
            $updateData['confirmed_at'] = now();
        }
        if ($newStatus === 'shipped' && !$order->shipped_at) {
            $updateData['shipped_at'] = now();
        }
        if ($newStatus === 'delivered' && !$order->delivered_at) {
            $updateData['delivered_at'] = now();
        }
        if ($newStatus === 'cancelled') {
            // If cancelling, refund stock
            foreach ($order->items as $item) {
                if ($item->product) {
                    $item->product->increment('stock_quantity', $item->quantity);
                }
            }
            // Could also trigger refund if paid
            if ($order->payment_status === 'paid') {
                $updateData['payment_status'] = 'refund_pending';
            }
        }

        $order->update($updateData);

        return response()->json([
            'success' => true,
            'message' => "Order status updated from {$oldStatus} to {$newStatus}",
            'order' => $this->formatOrderResponse($order->fresh()),
        ]);
    }

    /**
     * Format order for API response
     */
    private function formatOrderResponse(WebOrder $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'email' => $order->email,
            'phone' => $order->phone,
            'first_name' => $order->first_name,
            'last_name' => $order->last_name,
            'shipping_address' => $order->shipping_address,
            'subtotal' => (float) $order->subtotal,
            'shipping_cost' => (float) $order->shipping_cost,
            'tax_amount' => (float) $order->tax_amount,
            'discount_amount' => (float) $order->discount_amount,
            'loyalty_discount' => (float) ($order->loyalty_discount ?? 0),
            'total' => (float) $order->total,
            'currency' => $order->currency,
            'loyalty_points_earned' => $order->loyalty_points_earned,
            'loyalty_points_redeemed' => $order->loyalty_points_redeemed,
            'items' => $order->items->map(fn($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'product_sku' => $item->product_sku,
                'product_image' => $item->product_image,
                'unit_price' => (float) $item->unit_price,
                'quantity' => $item->quantity,
                'tax_amount' => (float) $item->tax_amount,
                'line_total' => (float) $item->line_total,
            ]),
            'created_at' => $order->created_at?->toISOString(),
            'confirmed_at' => $order->confirmed_at?->toISOString(),
            'paid_at' => $order->paid_at?->toISOString(),
            'shipped_at' => $order->shipped_at?->toISOString(),
            'delivered_at' => $order->delivered_at?->toISOString(),
        ];
    }
}
