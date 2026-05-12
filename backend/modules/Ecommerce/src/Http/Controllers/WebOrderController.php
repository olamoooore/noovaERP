<?php

namespace Modules\Ecommerce\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Ecommerce\Models\WebOrder;
use Modules\Ecommerce\Services\WebOrderPostingService;
use Modules\Ecommerce\Services\WebOrderEventReservationService;
use Modules\EventManagement\Models\Booking;
use Modules\PaymentGateway\Services\PaymentService;

class WebOrderController extends Controller
{
    public function __construct(
        private readonly WebOrderEventReservationService $webOrderEventReservationService,
    ) {
    }

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
            $this->reversePostingForCancellation($order, 'WebOrderController::updateStatus');
            $this->webOrderEventReservationService->releaseForOrder($order, 'Order cancelled by admin');
            // Could also trigger refund if paid
            if ($order->payment_status === 'paid') {
                $updateData['payment_status'] = WebOrder::PAYMENT_STATUS_REFUNDED;
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
     * Customer: Cancel a pending order
     */
    public function cancelOrder(Request $request, int $id): JsonResponse
    {
        $customer = $request->attributes->get('web_customer');

        if (!$customer) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $order = WebOrder::with('items.product')
            ->where('id', $id)
            ->where('customer_id', $customer->id)
            ->first();

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        if (!in_array($order->status, ['pending'])) {
            return response()->json([
                'error' => 'Only pending orders can be cancelled',
            ], 422);
        }

        $this->reversePostingForCancellation($order, 'WebOrderController::cancelOrder');
        $this->webOrderEventReservationService->releaseForOrder($order, 'Order cancelled by customer');

        $order->update([
            'status' => 'cancelled',
            'payment_status' => $order->payment_status === WebOrder::PAYMENT_STATUS_PAID
                ? WebOrder::PAYMENT_STATUS_REFUNDED
                : WebOrder::PAYMENT_STATUS_FAILED,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order cancelled successfully',
            'order' => $this->formatOrderResponse($order->fresh()),
        ]);
    }

    /**
     * Customer: Get invoice for an order
     */
    public function getInvoice(Request $request, int $id): JsonResponse
    {
        $customer = $request->attributes->get('web_customer');

        if (!$customer) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $order = WebOrder::with('items', 'invoice.lines')
            ->where('id', $id)
            ->where('customer_id', $customer->id)
            ->first();

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        if (!$order->invoice) {
            return response()->json(['error' => 'No invoice found for this order'], 404);
        }

        $invoice = $order->invoice;

        return response()->json([
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date,
                'due_date' => $invoice->due_date,
                'status' => $invoice->status,
                'subtotal' => (float) $invoice->subtotal,
                'tax_amount' => (float) $invoice->tax_amount,
                'discount_amount' => (float) $invoice->discount_amount,
                'total' => (float) $invoice->total,
                'paid_amount' => (float) $invoice->paid_amount,
                'balance' => (float) $invoice->balance,
                'notes' => $invoice->notes,
                'customer' => $invoice->customer ? [
                    'name' => $invoice->customer->name,
                    'email' => $invoice->customer->email,
                    'phone' => $invoice->customer->phone,
                ] : null,
                'lines' => $invoice->lines->map(fn($line) => [
                    'id' => $line->id,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price' => (float) $line->unit_price,
                    'line_total' => (float) $line->line_total,
                ]),
                'created_at' => $invoice->created_at?->toISOString(),
            ],
        ]);
    }

    /**
     * Customer: Retry payment for a pending order
     */
    public function retryPayment(Request $request, int $id): JsonResponse
    {
        $customer = $request->attributes->get('web_customer');

        if (!$customer) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $order = WebOrder::with('items')
            ->where('id', $id)
            ->where('customer_id', $customer->id)
            ->first();

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        if ($order->payment_status === 'paid') {
            return response()->json(['error' => 'Order is already paid'], 422);
        }

        if ($order->status === 'cancelled') {
            return response()->json(['error' => 'Cannot pay for a cancelled order'], 422);
        }

        $validated = $request->validate([
            'gateway_id' => 'nullable|integer',
        ]);

        try {
            $this->webOrderEventReservationService->ensureActiveReservations($order);

            $frontendOrigin = $this->resolveFrontendOrigin($request);

            $paymentService = app(PaymentService::class);

            $paymentIntent = $paymentService->createIntent([
                'amount' => $order->total,
                'currency' => $order->currency,
                'description' => "Web Order {$order->order_number} (retry)",
                'email' => $order->email,
                'gateway_id' => $validated['gateway_id'] ?? $request->input('gateway_id'),
                'redirect_url' => $frontendOrigin . '/payment/callback',
                'cancel_url' => $frontendOrigin . '/store/checkout',
                'metadata' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_id' => $customer->id,
                    'type' => 'web_order',
                    'retry' => true,
                ],
            ]);

            // Update order with new transaction reference
            $order->update([
                'payment_transaction_id' => $paymentIntent['transaction_id'] ?? $order->payment_transaction_id,
            ]);

            return response()->json([
                'success' => true,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'total' => (float) $order->total,
                'currency' => $order->currency,
                'gateway_name' => $paymentIntent['gateway_name'] ?? 'stripe',
                'payment_intent' => [
                    'client_secret' => $paymentIntent['client_secret'] ?? null,
                    'publishable_key' => $paymentIntent['publishable_key'] ?? null,
                    'payment_url' => $paymentIntent['payment_url'] ?? null,
                    'transaction_id' => $paymentIntent['transaction_id'] ?? null,
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Retry payment failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Payment initialization failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function resolveFrontendOrigin(Request $request): string
    {
        $origin = trim((string) $request->headers->get('origin', ''));
        if ($origin !== '') {
            return rtrim($origin, '/');
        }

        $referer = trim((string) $request->headers->get('referer', ''));
        if ($referer !== '') {
            $parts = parse_url($referer);
            if (!empty($parts['scheme']) && !empty($parts['host'])) {
                $port = isset($parts['port']) ? ':' . $parts['port'] : '';
                return rtrim($parts['scheme'] . '://' . $parts['host'] . $port, '/');
            }
        }

        return rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');
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
            'shipping_method' => $this->normalizeShippingMethod($order->shipping_method, (float) $order->shipping_cost),
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
            'event_bookings' => $this->loadEventBookingsForOrder($order),
        ];
    }

    private function loadEventBookingsForOrder(WebOrder $order): array
    {
        if (!Schema::hasTable('bookings')) {
            return [];
        }

        try {
            $bookings = Booking::with(['event', 'tickets.attendee'])
                ->where('booking_reference', 'like', sprintf('WEB%dEV%%', $order->id))
                ->get();
        } catch (\Throwable $e) {
            Log::warning('Failed to load event bookings for web order', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        return $bookings->map(function (Booking $booking) {
            $event = $booking->event;
            $eventDate = $event?->event_date ?? $event?->start_date ?? null;

            return [
                'id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'state' => $booking->state,
                'event' => $event ? [
                    'id' => $event->id,
                    'title' => $event->title,
                    'venue' => $event->venue,
                    'event_date' => $eventDate ? \Carbon\Carbon::parse($eventDate)->toIso8601String() : null,
                ] : null,
                'ticket_count' => $booking->tickets->count(),
                'tickets' => $booking->tickets->map(fn ($ticket) => [
                    'id' => $ticket->id,
                    'ticket_code' => $ticket->ticket_code,
                    'status' => $ticket->status,
                    'issued_at' => $ticket->issued_at?->toIso8601String(),
                    'attendee_name' => $ticket->attendee?->attendee_name,
                ])->values()->all(),
            ];
        })->values()->all();
    }

    private function normalizeShippingMethod(mixed $shippingMethod, float $shippingCost): ?array
    {
        if (empty($shippingMethod)) {
            return null;
        }

        if (is_string($shippingMethod)) {
            $label = trim($shippingMethod);

            return [
                'id' => null,
                'provider_code' => null,
                'provider_name' => null,
                'service_code' => null,
                'service_name' => $label !== '' ? $label : null,
                'label' => $label !== '' ? $label : null,
                'price' => $shippingCost,
            ];
        }

        if (!is_array($shippingMethod)) {
            return null;
        }

        $providerCode = trim((string) ($shippingMethod['provider_code'] ?? ''));
        $providerName = trim((string) ($shippingMethod['provider_name'] ?? ''));
        $serviceCode = trim((string) ($shippingMethod['service_code'] ?? ''));
        $serviceName = trim((string) ($shippingMethod['service_name'] ?? ($shippingMethod['name'] ?? '')));

        if ($providerName === '' && $providerCode !== '') {
            $providerName = strtoupper($providerCode);
        }

        if ($serviceName === '' && $serviceCode !== '') {
            $serviceName = strtoupper(str_replace('_', ' ', $serviceCode));
        }

        $label = trim(implode(' - ', array_filter([$providerName, $serviceName])));

        return [
            'id' => $shippingMethod['id'] ?? null,
            'provider_code' => $providerCode !== '' ? $providerCode : null,
            'provider_name' => $providerName !== '' ? $providerName : null,
            'service_code' => $serviceCode !== '' ? $serviceCode : null,
            'service_name' => $serviceName !== '' ? $serviceName : null,
            'label' => $label !== '' ? $label : null,
            'price' => array_key_exists('price', $shippingMethod)
                ? (float) ($shippingMethod['price'] ?? 0)
                : $shippingCost,
        ];
    }

    private function restoreStockForCancellation(WebOrder $order, string $context): void
    {
        try {
            $order->loadMissing('items.product');
            app(WebOrderPostingService::class)->restoreStock($order);
        } catch (\Throwable $e) {
            Log::warning("{$context} stock restore failed", [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function reversePostingForCancellation(WebOrder $order, string $context): void
    {
        try {
            $order->loadMissing('items.product');
            $postingService = app(WebOrderPostingService::class);

            if ($order->payment_status === WebOrder::PAYMENT_STATUS_PAID) {
                $postingService->reverseOrderPosting($order, 'Web order cancelled');
                return;
            }

            $postingService->restoreStock($order);
        } catch (\Throwable $e) {
            Log::warning("{$context} cancellation reversal failed", [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
