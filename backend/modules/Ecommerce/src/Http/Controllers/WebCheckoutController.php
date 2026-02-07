<?php

namespace Modules\Ecommerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Ecommerce\Models\WebOrder;
use Modules\Ecommerce\Models\WebOrderItem;
use Modules\PaymentGateway\Services\PaymentService;

class WebCheckoutController extends Controller
{
    public function __construct(
        private PaymentService $paymentService
    ) {}

    /**
     * Guest checkout - creates order without authentication
     */
    public function guestCheckout(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $customer = $request->attributes->get('web_customer');
            
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
            if (!$customer) {
                $validationRules['email'] = 'required|email|max:255';
            }
            
            $validated = $request->validate($validationRules);

            // Validate inventory for each item
            $insufficientStock = [];
            foreach ($validated['items'] as $item) {
                $product = Product::find($item['product_id']);
                if (!$product || $product->stock_quantity < $item['quantity']) {
                    $insufficientStock[] = [
                        'product_id' => $item['product_id'],
                        'name' => $product?->name ?? 'Unknown',
                        'requested' => $item['quantity'],
                        'available' => $product?->stock_quantity ?? 0,
                    ];
                }
            }

            if (!empty($insufficientStock)) {
                return response()->json([
                    'error' => 'Insufficient stock',
                    'items' => $insufficientStock,
                ], 422);
            }

            // Handle loyalty points redemption for authenticated customers
            $loyaltyDiscount = 0;
            $pointsRedeemed = 0;

            $customerId = auth('web-customer')->id() ?? null;

            if ($customerId && $request->has('loyalty_points_to_redeem') && $request->input('loyalty_points_to_redeem') > 0) {
                $pointsToRedeem = (int) $request->input('loyalty_points_to_redeem');
                
                try {
                    $redemptionService = app(\Modules\Loyalty\Services\RedemptionService::class);
                    
                    // Redeem points (without invoice_id - will update later)
                    $result = $redemptionService->redeemForDiscount(
                        customerId: $customerId,
                        points: $pointsToRedeem,
                        invoiceId: null // Will update after invoice creation
                    );
                    
                    if ($result['success']) {
                        $loyaltyDiscount = $result['discount_amount'];
                        $pointsRedeemed = $pointsToRedeem;
                    }
                } catch (\Exception $e) {
                    // Log error but don't fail checkout
                    \Log::warning('Loyalty redemption failed during checkout', [
                        'customer_id' => $customerId,
                        'points' => $pointsToRedeem,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Calculate totals
            $subtotal = 0;
            $taxAmount = 0;
            $items = [];

            foreach ($validated['items'] as $item) {
                $product = Product::find($item['product_id']);
                $lineSubtotal = $product->price * $item['quantity'];
                $lineTax = $lineSubtotal * 0.10; // 10% tax - make configurable
                $lineTotal = $lineSubtotal + $lineTax;

                $subtotal += $lineSubtotal;
                $taxAmount += $lineTax;

                $items[] = [
                    'product' => $product,
                    'quantity' => $item['quantity'],
                    'unit_price' => $product->price,
                    'tax_amount' => $lineTax,
                    'line_total' => $lineTotal,
                ];
            }

            $shippingCost = 0; // Add shipping calculation later
            $total = $subtotal + $taxAmount + $shippingCost - $loyaltyDiscount;

            // Generate guest token
            $guestToken = Str::uuid()->toString();

            // Create web order
            $order = WebOrder::create([
                'order_number' => WebOrder::generateOrderNumber(),
                'customer_id' => $customer?->id, // Link to customer if authenticated
                'email' => $customer?->email ?? $validated['email'],
                'phone' => $validated['phone'] ?? $customer?->phone ?? null,
                'first_name' => $validated['shipping_address']['first_name'],
                'last_name' => $validated['shipping_address']['last_name'],
                'shipping_address' => $validated['shipping_address'],
                'status' => 'pending',
                'payment_status' => 'pending',
                'subtotal' => $subtotal,
                'shipping_cost' => $shippingCost,
                'tax_amount' => $taxAmount,
                'discount_amount' => 0,
                'loyalty_discount' => $loyaltyDiscount,
                'total' => $total,
                'loyalty_points_redeemed' => $pointsRedeemed,
                'currency' => 'USD',
                'notes' => $validated['notes'] ?? null,
                'guest_token' => $customer ? null : $guestToken,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            // Create order items
            foreach ($items as $item) {
                WebOrderItem::create([
                    'web_order_id' => $order->id,
                    'product_id' => $item['product']->id,
                    'product_name' => $item['product']->name,
                    'product_sku' => $item['product']->sku,
                    'product_image' => $item['product']->image,
                    'unit_price' => $item['unit_price'],
                    'quantity' => $item['quantity'],
                    'tax_rate' => 10.00,
                    'tax_amount' => $item['tax_amount'],
                    'line_total' => $item['line_total'],
                ]);
            }

            // Create payment intent using PaymentGateway
            try {
                $paymentIntent = $this->paymentService->createIntent([
                    'amount' => $order->total,
                    'currency' => $order->currency,
                    'description' => "Order {$order->order_number}",
                    'metadata' => [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
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
                    'total' => $order->total,
                    'currency' => $order->currency,
                    'payment_intent' => [
                        'client_secret' => $paymentIntent['client_secret'],
                        'publishable_key' => $paymentIntent['publishable_key'],
                        'transaction_id' => $paymentIntent['transaction_id'],
                    ],
                    'guest_token' => $guestToken,
                ]);

            } catch (\Exception $e) {
                // Rollback order creation on payment error
                $order->delete();
                
                return response()->json([
                    'error' => 'Payment initialization failed',
                    'message' => $e->getMessage(),
                ], 500);
            }
        });
    }
}
