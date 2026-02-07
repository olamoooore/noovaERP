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
            'subtotal' => $order->subtotal,
            'shipping_cost' => $order->shipping_cost,
            'tax_amount' => $order->tax_amount,
            'discount_amount' => $order->discount_amount,
            'loyalty_discount' => $order->loyalty_discount,
            'total' => $order->total,
            'currency' => $order->currency,
            'items' => $order->items->map(fn($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'product_sku' => $item->product_sku,
                'product_image' => $item->product_image,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'tax_amount' => $item->tax_amount,
                'line_total' => $item->line_total,
            ]),
            'created_at' => $order->created_at,
            'paid_at' => $order->paid_at,
            'shipped_at' => $order->shipped_at,
        ];
    }
}
