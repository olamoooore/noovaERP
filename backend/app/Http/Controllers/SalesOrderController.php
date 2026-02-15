<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\POS\Models\PosOrder;
use Modules\Ecommerce\Models\WebOrder;

class SalesOrderController extends Controller
{
    /**
     * List all orders (POS and Web) with pagination and filters
     */
    public function index(Request $request): JsonResponse
    {
        $source = $request->input('source', 'all'); // all, pos, web
        $search = $request->input('search');
        $status = $request->input('status');
        $date = $request->input('date');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $perPage = $request->input('per_page', 20);

        $orders = collect();

        // Get POS orders if source is 'all' or 'pos'
        if ($source === 'all' || $source === 'pos') {
            $posQuery = PosOrder::with(['items', 'payments', 'createdBy', 'customer'])
                ->select([
                    'id',
                    'order_number',
                    'status',
                    'total',
                    'subtotal',
                    'tax_amount',
                    'discount_amount',
                    'loyalty_discount',
                    'currency',
                    'created_at',
                    'paid_at',
                    'customer_id',
                    'created_by',
                ])
                ->selectRaw("'pos' as source");

            if ($search) {
                $posQuery->where('order_number', 'like', "%{$search}%");
            }
            if ($status) {
                $posQuery->where('status', $status);
            }
            if ($date) {
                $posQuery->whereDate('created_at', $date);
            }
            if ($startDate) {
                $posQuery->whereDate('created_at', '>=', $startDate);
            }
            if ($endDate) {
                $posQuery->whereDate('created_at', '<=', $endDate);
            }

            $orders = $orders->merge($posQuery->get()->map(function ($order) {
                return $this->formatPosOrder($order);
            }));
        }

        // Get Web orders if source is 'all' or 'web'
        if ($source === 'all' || $source === 'web') {
            $webQuery = WebOrder::with(['items', 'customer'])
                ->select([
                    'id',
                    'order_number',
                    'status',
                    'payment_status',
                    'total',
                    'subtotal',
                    'shipping_cost',
                    'tax_amount',
                    'discount_amount',
                    'loyalty_discount',
                    'currency',
                    'created_at',
                    'paid_at',
                    'shipped_at',
                    'delivered_at',
                    'customer_id',
                    'first_name',
                    'last_name',
                    'email',
                ])
                ->selectRaw("'web' as source");

            if ($search) {
                $webQuery->where(function ($q) use ($search) {
                    $q->where('order_number', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%");
                });
            }
            if ($status) {
                $webQuery->where('status', $status);
            }
            if ($date) {
                $webQuery->whereDate('created_at', $date);
            }
            if ($startDate) {
                $webQuery->whereDate('created_at', '>=', $startDate);
            }
            if ($endDate) {
                $webQuery->whereDate('created_at', '<=', $endDate);
            }

            $orders = $orders->merge($webQuery->get()->map(function ($order) {
                return $this->formatWebOrder($order);
            }));
        }

        // Sort by created_at desc
        $sorted = $orders->sortByDesc('created_at');

        // Paginate manually
        $page = $request->input('page', 1);
        $items = $sorted->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => (int) $page,
                'last_page' => ceil($sorted->count() / $perPage),
                'per_page' => $perPage,
                'total' => $sorted->count(),
            ],
            'stats' => [
                'total_orders' => $orders->count(),
                'pos_orders' => $orders->where('source', 'pos')->count(),
                'web_orders' => $orders->where('source', 'web')->count(),
                'total_revenue' => $orders->sum('total'),
            ],
        ]);
    }

    /**
     * Format POS order for unified response
     */
    private function formatPosOrder(PosOrder $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'source' => 'pos',
            'status' => $order->status,
            'payment_status' => $order->status === 'paid' ? 'paid' : 'pending',
            'total' => (float) $order->total,
            'subtotal' => (float) $order->subtotal,
            'tax_amount' => (float) $order->tax_amount,
            'discount_amount' => (float) ($order->discount_amount ?? 0),
            'loyalty_discount' => (float) ($order->loyalty_discount ?? 0),
            'currency' => $order->currency ?? 'USD',
            'items_count' => $order->items?->count() ?? 0,
            'customer' => $order->customer ? [
                'id' => $order->customer->id,
                'name' => $order->customer->name,
                'email' => $order->customer->email,
            ] : null,
            'cashier' => $order->createdBy?->name,
            'created_at' => $order->created_at?->toISOString(),
            'paid_at' => $order->paid_at?->toISOString(),
        ];
    }

    /**
     * Format Web order for unified response
     */
    private function formatWebOrder(WebOrder $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'source' => 'web',
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'total' => (float) $order->total,
            'subtotal' => (float) $order->subtotal,
            'tax_amount' => (float) $order->tax_amount,
            'discount_amount' => (float) ($order->discount_amount ?? 0),
            'loyalty_discount' => (float) ($order->loyalty_discount ?? 0),
            'shipping_cost' => (float) ($order->shipping_cost ?? 0),
            'currency' => $order->currency ?? 'USD',
            'items_count' => $order->items?->count() ?? 0,
            'customer' => $order->customer ? [
                'id' => $order->customer->id,
                'name' => $order->customer->name,
                'email' => $order->customer->email,
            ] : [
                'name' => trim($order->first_name . ' ' . $order->last_name),
                'email' => $order->email,
            ],
            'cashier' => null,
            'created_at' => $order->created_at?->toISOString(),
            'paid_at' => $order->paid_at?->toISOString(),
            'shipped_at' => $order->shipped_at?->toISOString(),
            'delivered_at' => $order->delivered_at?->toISOString(),
        ];
    }
}
