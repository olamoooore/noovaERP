<?php

namespace Modules\Ecommerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Ecommerce\Models\WebOrder;
use Modules\Ecommerce\Models\WebOrderItem;
use Modules\PaymentGateway\Services\PaymentService;
use App\Notifications\AdminOrderAlert;
use App\Notifications\CustomerOrderConfirmation;
use Illuminate\Support\Facades\Notification;
use App\Models\User;
use App\Models\Warehouse;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Services\StockReservationService;

class WebCheckoutController extends Controller
{
    public function __construct(
        private PaymentService $paymentService,
        private InventoryService $inventoryService,
        private StockReservationService $stockReservationService,
    ) {}

    /**
     * Guest checkout - creates order without authentication.
     * Also creates/finds a Customer record in the system.
     */
    public function guestCheckout(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $webCustomer = $request->attributes->get('web_customer');

            $validationRules = [
                'items' => 'required|array|min:1',
                'items.*.product_id' => 'required|integer|exists:products,id',
                'items.*.quantity' => 'required|integer|min:1',
                'shipping_address' => 'required|array',
                'shipping_address.first_name' => 'required|string|max:100',
                'shipping_address.last_name' => 'required|string|max:100',
                'shipping_address.address' => 'required|string|max:255',
                'shipping_address.city' => 'required|string|max:100',
                'shipping_address.state' => 'nullable|string|max:100',
                'shipping_address.postal_code' => 'required|string|max:20',
                'shipping_address.country' => 'required|string|max:100',
                'phone' => 'nullable|string|max:50',
                'notes' => 'nullable|string|max:1000',
            ];

            // Only require email for guests
            if (!$webCustomer) {
                $validationRules['email'] = 'required|email|max:255';
            }

            $validated = $request->validate($validationRules);

            // Validate inventory for each item
            $insufficientStock = [];
            foreach ($validated['items'] as $item) {
                $product = Product::find($item['product_id']);
                if (!$product || !$product->is_active) {
                    $insufficientStock[] = [
                        'product_id' => $item['product_id'],
                        'name' => $product?->name ?? 'Unknown',
                        'requested' => $item['quantity'],
                        'available' => 0,
                        'reason' => 'Product not available',
                    ];
                } elseif ($product->track_inventory) {
                    $availability = $this->inventoryService->checkAvailability(
                        (int) $product->id,
                        (int) $item['quantity']
                    );

                    if (!($availability['is_available'] ?? false)) {
                        $insufficientStock[] = [
                            'product_id' => $item['product_id'],
                            'name' => $product->name,
                            'requested' => $item['quantity'],
                            'available' => (int) ($availability['total_available'] ?? 0),
                            'reason' => 'Insufficient stock',
                        ];
                    }
                } elseif (($product->stock_quantity ?? PHP_INT_MAX) < $item['quantity']) {
                    $insufficientStock[] = [
                        'product_id' => $item['product_id'],
                        'name' => $product->name,
                        'requested' => $item['quantity'],
                        'available' => $product->stock_quantity,
                        'reason' => 'Insufficient stock',
                    ];
                }
            }

            if (!empty($insufficientStock)) {
                return response()->json([
                    'error' => 'Stock issues found',
                    'items' => $insufficientStock,
                ], 422);
            }

            // ── Create or find Customer record ──────────────────────────
            $email = $webCustomer?->email ?? $validated['email'];
            $phone = $validated['phone'] ?? $webCustomer?->phone ?? null;
            $firstName = $validated['shipping_address']['first_name'];
            $lastName = $validated['shipping_address']['last_name'];
            $fullName = trim("{$firstName} {$lastName}");

            $systemCustomer = null;
            if ($webCustomer && $webCustomer->id) {
                $systemCustomer = Customer::find($webCustomer->id);
            }

            if (!$systemCustomer) {
                // Try to find existing customer by email
                $systemCustomer = Customer::where('email', $email)->first();

                if (!$systemCustomer) {
                    // Create new customer record (like Odoo res.partner)
                    $shippingAddr = is_array($validated['shipping_address']) ? json_encode($validated['shipping_address']) : $validated['shipping_address'];
                    $customerData = [
                        'name' => $fullName,
                        'email' => $email,
                        'phone' => $phone,
                        'shipping_address' => $shippingAddr,
                        'billing_address' => $shippingAddr,
                        'is_active' => true,
                        'notes' => 'Created from web shop checkout',
                    ];
                    $systemCustomer = Customer::create($customerData);

                    Log::info('Created new customer from web checkout', [
                        'customer_id' => $systemCustomer->id,
                        'email' => $email,
                    ]);
                } else {
                    // Update existing customer with latest shipping info
                    $systemCustomer->update([
                        'phone' => $phone ?: $systemCustomer->phone,
                        'shipping_address' => is_array($validated['shipping_address']) ? json_encode($validated['shipping_address']) : $validated['shipping_address'],
                    ]);
                }
            }

            $customerId = $systemCustomer->id;

            // ── Calculate totals first (needed for loyalty validation) ───────────
            $subtotal = 0;
            $taxAmount = 0;
            $items = [];

            foreach ($validated['items'] as $item) {
                $product = Product::find($item['product_id']);

                // Use product's tax rate if available, default to 0
                $taxRate = $product->tax_rate ?? 0;

                $lineSubtotal = $product->price * $item['quantity'];
                $lineTax = $lineSubtotal * ($taxRate / 100);
                $lineTotal = $lineSubtotal + $lineTax;

                $subtotal += $lineSubtotal;
                $taxAmount += $lineTax;

                $items[] = [
                    'product' => $product,
                    'quantity' => $item['quantity'],
                    'unit_price' => $product->price,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $lineTax,
                    'line_total' => $lineTotal,
                ];
            }

            $shippingCost = 0;

            // ── Generate guest token ─────────────────────────────────────
            $guestToken = Str::uuid()->toString();

            // ── Create web order first (needed for loyalty reference) ───────────
            $order = WebOrder::create([
                'order_number' => WebOrder::generateOrderNumber(),
                'customer_id' => $customerId,
                'email' => $email,
                'phone' => $phone,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'shipping_address' => $validated['shipping_address'],
                'status' => 'pending',
                'payment_status' => 'pending',
                'subtotal' => $subtotal,
                'shipping_cost' => $shippingCost,
                'tax_amount' => $taxAmount,
                'discount_amount' => 0,
                'loyalty_discount' => 0,
                'total' => $subtotal + $taxAmount + $shippingCost, // Will be updated after loyalty
                'loyalty_points_redeemed' => 0,
                'currency' => 'USD',
                'notes' => $validated['notes'] ?? null,
                'guest_token' => $guestToken,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            // ── Handle loyalty points redemption ─────────────────────────
            $loyaltyDiscount = 0;
            $pointsRedeemed = 0;

            if ($request->has('loyalty_points_to_redeem') && $request->input('loyalty_points_to_redeem') > 0) {
                $pointsToRedeem = (int) $request->input('loyalty_points_to_redeem');

                try {
                    $redemptionService = app(\Modules\Loyalty\Services\RedemptionService::class);

                    $result = $redemptionService->redeemForDiscount(
                        customerId: $customerId,
                        points: $pointsToRedeem,
                        invoiceId: null,
                        referenceType: 'web_order',
                        referenceId: $order->id
                    );

                    if ($result['success']) {
                        $loyaltyDiscount = $result['discount_amount'];
                        $pointsRedeemed = $pointsToRedeem;
                    }
                } catch (\Exception $e) {
                    Log::warning('Loyalty redemption failed during checkout', [
                        'customer_id' => $customerId,
                        'points' => $pointsToRedeem,
                        'error' => $e->getMessage(),
                    ]);
                    // Don't fail checkout - just skip loyalty discount
                }
            }

            // Update order with loyalty discount and final total
            $total = max(0, $subtotal + $taxAmount + $shippingCost - $loyaltyDiscount);
            $order->update([
                'loyalty_discount' => $loyaltyDiscount,
                'loyalty_points_redeemed' => $pointsRedeemed,
                'total' => $total,
            ]);

            // ── Create order items & reserve stock ───────────────────────
            $warehouseId = Warehouse::getDefault()?->id;
            $orderItems = [];

            foreach ($items as $item) {
                $orderItem = WebOrderItem::create([
                    'web_order_id' => $order->id,
                    'product_id' => $item['product']->id,
                    'product_name' => $item['product']->name,
                    'product_sku' => $item['product']->sku ?? null,
                    'product_image' => $item['product']->image ?? null,
                    'unit_price' => $item['unit_price'],
                    'quantity' => $item['quantity'],
                    'tax_rate' => $item['tax_rate'],
                    'tax_amount' => $item['tax_amount'],
                    'line_total' => $item['line_total'],
                ]);
                $orderItems[] = $orderItem;
            }

            // ── Reserve stock to prevent oversell between checkout and payment ──
            if ($warehouseId) {
                foreach ($orderItems as $orderItem) {
                    $product = Product::find($orderItem->product_id);
                    if ($product && $product->track_inventory) {
                        try {
                            $this->stockReservationService->reserve(
                                productId:            $orderItem->product_id,
                                warehouseId:          $warehouseId,
                                quantity:             $orderItem->quantity,
                                sourceDocumentType:   'WebOrder',
                                sourceDocumentId:     $order->id,
                                sourceDocumentLineId: $orderItem->id,
                                expiresAt:            now()->addMinutes(30), // 30-min TTL for unpaid orders
                                notes:                "Web checkout reservation for {$order->order_number}",
                            );
                        } catch (\Exception $e) {
                            Log::warning('Stock reservation failed during checkout — proceeding without reservation', [
                                'order_id' => $order->id,
                                'product_id' => $orderItem->product_id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }

            // ── Create payment intent ────────────────────────────────────
            try {
                $paymentIntent = $this->paymentService->createIntent([
                    'amount' => $order->total,
                    'currency' => $order->currency,
                    'description' => "Web Order {$order->order_number}",
                    'email' => $email,
                    'gateway_id' => $request->input('gateway_id'),
                    'customer_id' => $customerId,
                    'metadata' => [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'customer_id' => $customerId,
                        'type' => 'web_order',
                    ],
                ]);

                // Update order with transaction reference
                $order->update([
                    'payment_transaction_id' => $paymentIntent['transaction_id'] ?? null,
                ]);

                return response()->json([
                    'success' => true,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'total' => (float) $order->total,
                    'currency' => $order->currency,
                    'gateway_name' => $paymentIntent['gateway_name'] ?? 'stripe',
                    'payment_intent' => [
                        'client_secret' => $paymentIntent['client_secret'],
                        'publishable_key' => $paymentIntent['publishable_key'],
                        'transaction_id' => $paymentIntent['transaction_id'],
                        'payment_url' => $paymentIntent['payment_url'] ?? null,
                    ],
                    'guest_token' => $guestToken,
                    'customer_id' => $customerId,
                ]);

            } catch (\Exception $e) {
                Log::error('Payment initialization failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);

                // Mark payment as failed but keep the order
                // Wrap in try/catch since PostgreSQL aborts entire transaction on error
                try {
                    $order->update(['payment_status' => 'failed']);
                } catch (\Exception $updateEx) {
                    Log::warning('Could not update order payment status after failure', [
                        'order_id' => $order->id,
                        'error' => $updateEx->getMessage(),
                    ]);
                }

                return response()->json([
                    'error' => 'Payment initialization failed',
                    'message' => $e->getMessage(),
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ], 500);
            }
        });
    }

    /**
     * Confirm payment after Stripe completes.
     * Called by frontend after successful stripe.confirmPayment().
     */
    public function confirmPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => 'required|integer',
            'payment_intent_id' => 'required|string',
            'guest_token' => 'nullable|string',
        ]);

        $query = WebOrder::query()->where('id', $validated['order_id']);

        // Verify access: either by guest token or customer session
        $webCustomer = $request->attributes->get('web_customer');
        if ($webCustomer) {
            $query->where('customer_id', $webCustomer->id);
        } elseif (!empty($validated['guest_token'])) {
            $query->where('guest_token', $validated['guest_token']);
        } else {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $order = $query->first();
        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        if ($order->payment_status === 'paid') {
            return response()->json([
                'success' => true,
                'message' => 'Order already paid',
                'order' => $this->formatOrderResponse($order),
            ]);
        }

        try {
            // Verify payment status with the gateway
            $paymentStatus = $this->paymentService->confirmPayment($validated['payment_intent_id']);

            if ($paymentStatus['status'] === 'completed') {
                return DB::transaction(function () use ($order) {
                    // Mark order as paid
                    $order->update([
                        'payment_status' => 'paid',
                        'status' => 'confirmed',
                        'paid_at' => now(),
                        'confirmed_at' => now(),
                    ]);

                    // Convert to invoice
                    $invoice = null;
                    try {
                        $invoice = $order->convertToInvoice();
                        Log::info('Web order converted to invoice', [
                            'order_id' => $order->id,
                            'invoice_id' => $invoice->id ?? null,
                        ]);
                        // Refresh order to load invoice relationship
                        $order = $order->fresh(['invoice', 'items']);
                    } catch (\Exception $e) {
                        Log::warning('Failed to convert web order to invoice', [
                            'order_id' => $order->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    // Award loyalty points
                    $this->awardLoyaltyPoints($order);

                    // Update customer purchase stats
                    $this->updateCustomerStats($order);

                    // Notifications
                    try {
                        // 1. Notify Customer
                        // Always use AnonymousNotifiable (Notification::route) instead of
                        // $customer->notify() because the Customer model lives in the tenant
                        // schema. When the queue worker deserializes the job, it tries to
                        // restore the Customer model using the default 'public' search_path,
                        // fails to find it, and silently discards the notification.
                        $customerEmail = $order->email;
                        if (!$customerEmail && $order->customer_id) {
                            $customer = Customer::find($order->customer_id);
                            $customerEmail = $customer?->email;
                        }

                        if ($customerEmail) {
                            Notification::route('mail', $customerEmail)
                                        ->notify(new CustomerOrderConfirmation($order, 'Web'));
                        }

                        // 2. Notify Admins
                        $admins = User::whereHas('roles', function($q) {
                             $q->where('name', 'like', '%admin%');
                        })->get();
                         
                        if ($admins->isEmpty()) {
                            $admin = User::find(1);
                            if ($admin) $admins = collect([$admin]);
                        }

                        if ($admins->isNotEmpty()) {
                            Notification::send($admins, new AdminOrderAlert($order, 'Web'));
                        }

                    } catch (\Exception $e) {
                         Log::error('Web Notification Failed: ' . $e->getMessage());
                    }

                    return response()->json([
                        'success' => true,
                        'message' => 'Payment confirmed',
                        'order' => $this->formatOrderResponse($order->fresh()),
                    ]);
                });
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not yet completed',
                    'status' => $paymentStatus['status'],
                ], 402);
            }
        } catch (\Exception $e) {
            Log::error('Payment confirmation failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Payment confirmation failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available payment gateways for the checkout page.
     */
    public function getPaymentGateways(): JsonResponse
    {
        $gateways = $this->paymentService->getAvailableGateways();

        return response()->json([
            'gateways' => $gateways,
            'has_gateways' => count($gateways) > 0,
        ]);
    }

    /**
     * Award loyalty points after successful payment.
     */
    private function awardLoyaltyPoints(WebOrder $order): void
    {
        if (!$order->customer_id) return;

        try {
            $earningService = app(\Modules\Loyalty\Services\PointsEarningService::class);
            $result = $earningService->awardPoints(
                customerId: $order->customer_id,
                amount: (float) $order->subtotal,
                referenceType: 'web_order',
                referenceId: $order->id,
                description: "Points earned from web order {$order->order_number}"
            );

            if ($result['success'] ?? false) {
                $order->update([
                    'loyalty_points_earned' => $result['points_earned'] ?? 0,
                ]);

                Log::info('Loyalty points awarded for web order', [
                    'order_id' => $order->id,
                    'points' => $result['points_earned'] ?? 0,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to award loyalty points', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update customer purchase statistics.
     */
    private function updateCustomerStats(WebOrder $order): void
    {
        if (!$order->customer_id) return;

        try {
            $customer = Customer::find($order->customer_id);
            if ($customer) {
                $customer->increment('total_orders');
                $customer->increment('total_purchases', (float) $order->total);
                $customer->update([
                    'last_interaction_at' => now(),
                    'average_order_value' => $customer->total_orders > 0
                        ? $customer->total_purchases / $customer->total_orders
                        : 0,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to update customer stats', [
                'customer_id' => $order->customer_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Format order response.
     */
    private function formatOrderResponse(WebOrder $order): array
    {
        $order->load('items');

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'email' => $order->email,
            'first_name' => $order->first_name,
            'last_name' => $order->last_name,
            'shipping_address' => $order->shipping_address,
            'subtotal' => (float) $order->subtotal,
            'tax_amount' => (float) $order->tax_amount,
            'shipping_cost' => (float) $order->shipping_cost,
            'discount_amount' => (float) $order->discount_amount,
            'loyalty_discount' => (float) ($order->loyalty_discount ?? 0),
            'total' => (float) $order->total,
            'loyalty_points_earned' => $order->loyalty_points_earned,
            'loyalty_points_redeemed' => $order->loyalty_points_redeemed,
            'items' => $order->items->map(fn($item) => [
                'id' => $item->id,
                'product_name' => $item->product_name,
                'product_sku' => $item->product_sku,
                'product_image' => $item->product_image,
                'unit_price' => (float) $item->unit_price,
                'quantity' => $item->quantity,
                'tax_amount' => (float) $item->tax_amount,
                'line_total' => (float) $item->line_total,
            ]),
            'created_at' => $order->created_at?->toISOString(),
            'paid_at' => $order->paid_at?->toISOString(),
        ];
    }
}
