<?php

namespace Modules\Ecommerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\CustomerContactSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Ecommerce\Models\WebOrder;
use Modules\Ecommerce\Models\WebOrderItem;
use Modules\Ecommerce\Services\CheckoutQueueAdmissionService;
use Modules\Ecommerce\Services\WebOrderEventReservationService;
use Modules\Ecommerce\Services\WebOrderNotificationService;
use Modules\EventManagement\Services\WebOrderTicketFulfillmentService;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Services\StockReservationService;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PaymentService;
use Modules\PaymentGateway\Services\PaymentTransactionCompletionService;

class WebCheckoutController extends Controller
{
    public function __construct(
        private PaymentService $paymentService,
        private PaymentTransactionCompletionService $completionService,
        private InventoryService $inventoryService,
        private StockReservationService $stockReservationService,
        private CheckoutQueueAdmissionService $queueAdmissionService,
        private WebOrderEventReservationService $webOrderEventReservationService,
        private WebOrderNotificationService $webOrderNotificationService,
        private WebOrderTicketFulfillmentService $webOrderTicketFulfillmentService,
    ) {}

    /**
     * Guest checkout - creates order without authentication.
     * Also creates/finds a Customer record in the system.
     */
    public function guestCheckout(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            if ($request->boolean('queue_required', false)) {
                $campaignKey = (string) $request->input('campaign_key', 'default');
                $queueToken = (string) $request->input('queue_token', '');

                if ($queueToken === '' || ! $this->queueAdmissionService->canProceedToCheckout($campaignKey, $queueToken)) {
                    return response()->json([
                        'error' => 'QUEUE_ADMISSION_REQUIRED',
                        'message' => 'You must hold an active queue slot before checkout.',
                        'campaign_key' => $campaignKey,
                        'join_endpoint' => '/api/v1/web-shop/queue/join',
                    ], 429);
                }
            }

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
                'shipping_method' => 'nullable|array',
                'shipping_method.id' => 'nullable|string',
                'shipping_method.provider_code' => 'nullable|string',
                'shipping_method.provider_name' => 'nullable|string',
                'shipping_method.service_code' => 'nullable|string',
                'shipping_method.service_name' => 'nullable|string',
                'shipping_method.price' => 'nullable|numeric|min:0',
                'phone' => 'nullable|string|max:50',
                'notes' => 'nullable|string|max:1000',
            ];

            // Only require email for guests
            if (! $webCustomer) {
                $validationRules['email'] = 'required|email|max:255';
            }

            $validated = $request->validate($validationRules);

            // Validate inventory for each item
            $insufficientStock = [];
            $candidateWarehouseIds = null;
            $requiresWarehouseFulfillment = false;

            foreach ($validated['items'] as $item) {
                $product = Product::find($item['product_id']);
                if (! $product || ! $product->is_active) {
                    $insufficientStock[] = [
                        'product_id' => $item['product_id'],
                        'name' => $product?->name ?? 'Unknown',
                        'requested' => $item['quantity'],
                        'available' => 0,
                        'reason' => 'Product not available',
                    ];
                } elseif ($product->track_inventory) {
                    $requestedQuantity = (int) $item['quantity'];
                    $availability = $this->inventoryService->checkAvailability(
                        (int) $product->id,
                        $requestedQuantity
                    );

                    $eligibleWarehouseIds = collect($availability['warehouses'] ?? [])
                        ->filter(fn (array $warehouse) => (int) ($warehouse['available'] ?? 0) >= $requestedQuantity)
                        ->pluck('warehouse_id')
                        ->map(fn ($warehouseId) => (int) $warehouseId)
                        ->filter()
                        ->values()
                        ->all();

                    if (! ($availability['is_available'] ?? false)) {
                        $insufficientStock[] = [
                            'product_id' => $item['product_id'],
                            'name' => $product->name,
                            'requested' => $item['quantity'],
                            'available' => (int) ($availability['total_available'] ?? 0),
                            'reason' => 'Insufficient stock',
                        ];
                    } elseif (empty($eligibleWarehouseIds)) {
                        $insufficientStock[] = [
                            'product_id' => $item['product_id'],
                            'name' => $product->name,
                            'requested' => $requestedQuantity,
                            'available' => (int) ($availability['total_available'] ?? 0),
                            'reason' => 'No single warehouse can fulfill requested quantity',
                        ];
                    }

                    if (! empty($eligibleWarehouseIds)) {
                        $requiresWarehouseFulfillment = true;
                        $candidateWarehouseIds = $candidateWarehouseIds === null
                            ? $eligibleWarehouseIds
                            : array_values(array_intersect($candidateWarehouseIds, $eligibleWarehouseIds));
                    }
                }
            }

            if (! empty($insufficientStock)) {
                return response()->json([
                    'error' => 'Stock issues found',
                    'items' => $insufficientStock,
                ], 422);
            }

            if ($requiresWarehouseFulfillment && empty($candidateWarehouseIds)) {
                return response()->json([
                    'error' => 'Stock issues found',
                    'items' => [[
                        'product_id' => null,
                        'name' => 'Order',
                        'requested' => count($validated['items']),
                        'available' => 0,
                        'reason' => 'No single warehouse can fulfill all requested items',
                    ]],
                ], 422);
            }

            $fulfillmentWarehouseId = null;
            if ($requiresWarehouseFulfillment) {
                $fulfillmentWarehouseId = Warehouse::query()
                    ->whereIn('id', $candidateWarehouseIds)
                    ->where('is_active', true)
                    ->orderByDesc('is_default')
                    ->orderBy('id')
                    ->value('id');

                if (! $fulfillmentWarehouseId) {
                    return response()->json([
                        'error' => 'Stock issues found',
                        'items' => [[
                            'product_id' => null,
                            'name' => 'Order',
                            'requested' => count($validated['items']),
                            'available' => 0,
                            'reason' => 'No active warehouse can fulfill all requested items',
                        ]],
                    ], 422);
                }
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

            if (! $systemCustomer) {
                // Try to find existing customer by email
                $systemCustomer = Customer::where('email', $email)->first();

                if (! $systemCustomer) {
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
                    $systemCustomer = app(\App\Services\CanonicalCustomerService::class)->create($customerData);

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

            // TASK TAX-003: Use TaxResolutionService for canonical tax calculation
            $taxResolutionService = app(\App\Services\TaxResolutionService::class);

            // ── Calculate totals first (needed for loyalty validation) ───────────
            $subtotal = 0;
            $taxAmount = 0;
            $items = [];

            foreach ($validated['items'] as $item) {
                $product = Product::find($item['product_id']);

                // TASK TAX-003: Use canonical TaxResolutionService instead of inline logic
                $taxInfo = $taxResolutionService->resolveTaxForProduct($product);
                $taxRate = $taxInfo['rate'];

                $lineSubtotal = $product->price * $item['quantity'];

                // Calculate tax using canonical service
                $taxCalc = $taxResolutionService->calculateTax($lineSubtotal, $taxRate, $taxInfo['is_inclusive']);

                $subtotal += $taxCalc['net'];
                $taxAmount += $taxCalc['tax'];

                $items[] = [
                    'product' => $product,
                    'quantity' => $item['quantity'],
                    'unit_price' => $product->price,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxCalc['tax'],
                    'line_total' => $taxCalc['gross'],
                ];
            }

            $shippingCost = 0;
            $shippingMethod = $validated['shipping_method'] ?? null;

            // Re-quote shipping server-side using selected provider/service when possible.
            $selectedProviderCode = strtolower((string) ($validated['shipping_method']['provider_code'] ?? ''));
            $selectedServiceCode = strtoupper((string) ($validated['shipping_method']['service_code'] ?? ''));

            if ($selectedProviderCode !== '' && $selectedServiceCode !== '') {
                try {
                    $estimatedWeightKg = max(
                        0.1,
                        collect($validated['items'])->sum(fn (array $item) => ((int) $item['quantity']) * 0.5)
                    );

                    $countryRaw = strtoupper(trim((string) ($validated['shipping_address']['country'] ?? 'MY')));
                    $countryCode = match ($countryRaw) {
                        'SINGAPORE', 'SG' => 'SG',
                        'MALAYSIA', 'MY' => 'MY',
                        default => strlen($countryRaw) === 2 ? $countryRaw : 'MY',
                    };

                    $rateShopper = app(\Modules\Fulfillment\Services\RateShopperService::class);
                    $rateRequest = new \Modules\Fulfillment\Contracts\RateRequest(
                        weight: $estimatedWeightKg,
                        originCountry: 'MY',
                        destinationCountry: $countryCode,
                        destinationPostalCode: (string) ($validated['shipping_address']['postal_code'] ?? ''),
                    );

                    $rates = $rateShopper->shopRates($rateRequest);
                    $matchedRate = $rates->first(function ($rate) use ($selectedProviderCode, $selectedServiceCode) {
                        return strtolower((string) ($rate->provider_code ?? '')) === $selectedProviderCode
                            && strtoupper((string) ($rate->service_code ?? '')) === $selectedServiceCode;
                    });

                    if ($matchedRate) {
                        $shippingCost = (float) $matchedRate->price;
                        $shippingMethod = [
                            'id' => (string) ($matchedRate->provider_code.'_'.$matchedRate->service_code),
                            'provider_code' => (string) ($matchedRate->provider_code ?? ''),
                            'provider_name' => (string) ($matchedRate->provider_name ?? ''),
                            'service_code' => (string) ($matchedRate->service_code ?? ''),
                            'service_name' => (string) ($matchedRate->service_name ?? ''),
                            'price' => (float) $matchedRate->price,
                            'currency' => (string) ($matchedRate->currency ?? 'USD'),
                        ];
                    } elseif (! empty($validated['shipping_method']['price'])) {
                        $shippingCost = (float) $validated['shipping_method']['price'];
                    }
                } catch (\Throwable $e) {
                    Log::warning('Failed to re-quote shipping during checkout', [
                        'provider' => $selectedProviderCode,
                        'service' => $selectedServiceCode,
                        'error' => $e->getMessage(),
                    ]);

                    if (! empty($validated['shipping_method']['price'])) {
                        $shippingCost = (float) $validated['shipping_method']['price'];
                    }
                }
            } elseif (! empty($validated['shipping_method']['price'])) {
                $shippingCost = (float) $validated['shipping_method']['price'];
            }

            $shippingMethod = $this->normalizeShippingMethod($shippingMethod, $shippingCost);

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
                'shipping_method' => $shippingMethod,
                'status' => 'pending',
                'payment_status' => 'pending',
                'subtotal' => $subtotal,
                'shipping_cost' => $shippingCost,
                'tax_amount' => $taxAmount,
                'discount_amount' => 0,
                'loyalty_discount' => 0,
                'total' => $subtotal + $taxAmount + $shippingCost, // Will be updated after loyalty
                'loyalty_points_redeemed' => 0,
                'fulfillment_warehouse_id' => $fulfillmentWarehouseId,
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
            $warehouseId = $order->fulfillment_warehouse_id;
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
                                productId: $orderItem->product_id,
                                warehouseId: $warehouseId,
                                quantity: $orderItem->quantity,
                                sourceDocumentType: 'WebOrder',
                                sourceDocumentId: $order->id,
                                sourceDocumentLineId: $orderItem->id,
                                expiresAt: now()->addMinutes(30), // 30-min TTL for unpaid orders
                                notes: "Web checkout reservation for {$order->order_number}",
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

            $this->webOrderEventReservationService->reserveForOrder($order, collect($orderItems));

            $frontendOrigin = $this->resolveFrontendOrigin($request);

            // ── Create payment intent ────────────────────────────────────
            try {
                $paymentIntent = $this->paymentService->createIntent([
                    'amount' => $order->total,
                    'currency' => $order->currency,
                    'description' => "Web Order {$order->order_number}",
                    'email' => $email,
                    'gateway_id' => $request->input('gateway_id'),
                    'customer_id' => $customerId,
                    'redirect_url' => $frontendOrigin.'/payment/callback',
                    'cancel_url' => $frontendOrigin.'/store/checkout',
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
        } elseif (! empty($validated['guest_token'])) {
            $query->where('guest_token', $validated['guest_token']);
        } else {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $order = $query->first();
        if (! $order) {
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
            $paymentIntentId = $validated['payment_intent_id'];
            $paymentStatus = $this->paymentService->confirmPayment($validated['payment_intent_id']);

            if ($paymentStatus['status'] === 'completed') {
                return DB::transaction(function () use ($order, $paymentStatus, $paymentIntentId) {
                    $transaction = PaymentTransaction::where('transaction_id', $paymentIntentId)->first();

                    if (! $transaction) {
                        throw new \RuntimeException('Payment transaction not found for confirmed web order payment.');
                    }

                    $alreadyProcessed = $transaction->processed_at !== null;

                    $recoveryApplied = false;
                    $transactionErrorMessage = null;

                    try {
                        $this->completionService->complete(
                            $transaction,
                            (string) ($paymentStatus['payment_method'] ?? 'card')
                        );
                    } catch (\Throwable $e) {
                        if (! $this->shouldOperationallyRecoverCapturedPayment($e)) {
                            throw $e;
                        }

                        $recoveryApplied = true;
                        $transactionErrorMessage = $this->recoverOperationalPaymentState($order, $transaction);

                        Log::warning('Web checkout payment completed with operational recovery after GL posting failure', [
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                            'transaction_id' => $transaction->transaction_id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $transaction->refresh();
                    $transaction->update([
                        'status' => 'completed',
                        'processed_at' => $transaction->processed_at ?? now(),
                        'error_message' => $transactionErrorMessage,
                    ]);

                    $workingOrder = $order->fresh(['invoice', 'items']);

                    if (! $alreadyProcessed) {
                        $this->webOrderNotificationService->dispatchPaidOrderNotifications($workingOrder);
                    }

                    $this->webOrderTicketFulfillmentService->syncPaidWebOrder($workingOrder->fresh('items'));

                    return response()->json([
                        'success' => true,
                        'message' => $recoveryApplied
                            ? 'Payment confirmed; accounting follow-up required'
                            : 'Payment confirmed',
                        'recovery_applied' => $recoveryApplied,
                        'order' => $this->formatOrderResponse($workingOrder->fresh()),
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
            PaymentTransaction::where('transaction_id', $validated['payment_intent_id'] ?? null)
                ->whereNull('processed_at')
                ->update([
                    'status' => 'pending',
                    'error_message' => $e->getMessage(),
                ]);

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

    private function shouldOperationallyRecoverCapturedPayment(\Throwable $exception): bool
    {
        return str_contains($exception->getMessage(), 'Journal entry is unbalanced');
    }

    private function recoverOperationalPaymentState(WebOrder $order, PaymentTransaction $transaction): string
    {
        $order->refresh();

        if ($order->status === WebOrder::STATUS_PENDING) {
            $order->confirm();
        }

        if ($order->payment_status === WebOrder::PAYMENT_STATUS_PENDING) {
            $order->markPaid();
        }

        if (! $order->invoice_id) {
            $order->convertToInvoice();
            $order->refresh();
        }

        $message = 'Operationally completed during checkout confirmation; accounting posting requires follow-up.';

        $transaction->update([
            'status' => 'completed',
            'processed_at' => $transaction->processed_at ?? now(),
            'error_message' => $message,
        ]);

        return $message;
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
            'shipping_method' => $this->normalizeShippingMethod($order->shipping_method, (float) $order->shipping_cost),
            'subtotal' => (float) $order->subtotal,
            'tax_amount' => (float) $order->tax_amount,
            'shipping_cost' => (float) $order->shipping_cost,
            'discount_amount' => (float) $order->discount_amount,
            'loyalty_discount' => (float) ($order->loyalty_discount ?? 0),
            'total' => (float) $order->total,
            'loyalty_points_earned' => $order->loyalty_points_earned,
            'loyalty_points_redeemed' => $order->loyalty_points_redeemed,
            'items' => $order->items->map(fn ($item) => [
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

        if (! is_array($shippingMethod)) {
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

    private function resolveFrontendOrigin(Request $request): string
    {
        $origin = trim((string) $request->headers->get('origin', ''));
        if ($origin !== '') {
            return rtrim($origin, '/');
        }

        $referer = trim((string) $request->headers->get('referer', ''));
        if ($referer !== '') {
            $parts = parse_url($referer);
            if (! empty($parts['scheme']) && ! empty($parts['host'])) {
                $port = isset($parts['port']) ? ':'.$parts['port'] : '';

                return rtrim($parts['scheme'].'://'.$parts['host'].$port, '/');
            }
        }

        return rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');
    }
}
