<?php

namespace Modules\POS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\Setting;
use App\Models\Tax;
use Modules\Inventory\Services\InventoryService;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosOrderItem;
use Modules\POS\Models\PosPayment;
use Modules\POS\Services\PosPaymentCompletionService;
use Modules\POS\Services\PosPostingService;
use Modules\Loyalty\Services\RedemptionService;
use Modules\Loyalty\Services\PointsEarningService;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Notifications\AdminOrderAlert;
use App\Notifications\CustomerOrderConfirmation;
use Illuminate\Support\Facades\Notification;
use App\Models\User;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class PosOrderController extends Controller
{
    /**
     * List all POS orders with pagination and filters
     */
    public function index(Request $request)
    {
        $query = PosOrder::with(['items.product', 'items.variant', 'payments', 'createdBy'])
            ->orderBy('created_at', 'desc');

        // Search by order number
        if ($request->has('search') && $request->search) {
            $query->where('order_number', 'like', '%' . $request->search . '%');
        }

        // Filter by status
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Filter by date
        if ($request->has('date') && $request->date) {
            $query->whereDate('created_at', $request->date);
        }

        // Filter by date range
        if ($request->has('start_date') && $request->start_date) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }
        if ($request->has('end_date') && $request->end_date) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        $perPage = $request->input('per_page', 20);
        return $query->paginate($perPage);
    }

    /**
     * Get POS dashboard statistics
     */
    public function dashboard(Request $request)
    {
        $today = now()->toDateString();
        $thisMonth = now()->startOfMonth()->toDateString();

        // Today's sales
        $todayOrders = PosOrder::where('status', 'paid')
            ->whereDate('paid_at', $today)
            ->get();

        $todayTotal = $todayOrders->sum('total');
        $todayCount = $todayOrders->count();

        // This month's sales
        $monthOrders = PosOrder::where('status', 'paid')
            ->whereDate('paid_at', '>=', $thisMonth)
            ->get();

        $monthTotal = $monthOrders->sum('total');
        $monthCount = $monthOrders->count();

        // Recent orders
        $recentOrders = PosOrder::with(['items', 'payments'])
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        // Daily sales for chart (last 30 days)
        $dailySales = PosOrder::where('status', 'paid')
            ->whereDate('paid_at', '>=', now()->subDays(30))
            ->selectRaw('DATE(paid_at) as date, SUM(total) as total, COUNT(*) as count')
            ->groupByRaw('DATE(paid_at)')
            ->orderByRaw('DATE(paid_at)')
            ->get();

        return response()->json([
            'today' => [
                'total' => $todayTotal,
                'count' => $todayCount,
            ],
            'month' => [
                'total' => $monthTotal,
                'count' => $monthCount,
            ],
            'recent_orders' => $recentOrders,
            'daily_sales' => $dailySales,
        ]);
    }

    /**
     * Get reports data
     */
    public function reports(Request $request)
    {
        $type = $request->input('type', 'daily'); // daily, monthly
        $startDate = $request->input('start_date', now()->subDays(30)->toDateString());
        $endDate = $request->input('end_date', now()->toDateString());

        $query = PosOrder::where('status', 'paid')
            ->whereDate('paid_at', '>=', $startDate)
            ->whereDate('paid_at', '<=', $endDate);

        if ($type === 'daily') {
            $data = $query
                ->selectRaw('DATE(paid_at) as date, SUM(total) as total, SUM(tax_amount) as tax, COUNT(*) as orders')
                ->groupByRaw('DATE(paid_at)')
                ->orderByRaw('DATE(paid_at)')
                ->get();
        } else {
            $data = $query
                ->selectRaw('EXTRACT(YEAR FROM paid_at) as year, EXTRACT(MONTH FROM paid_at) as month, SUM(total) as total, SUM(tax_amount) as tax, COUNT(*) as orders')
                ->groupByRaw('EXTRACT(YEAR FROM paid_at), EXTRACT(MONTH FROM paid_at)')
                ->orderByRaw('EXTRACT(YEAR FROM paid_at)')
                ->orderByRaw('EXTRACT(MONTH FROM paid_at)')
                ->get();
        }

        // Summary
        $summary = [
            'total_sales' => $query->sum('total'),
            'total_tax' => $query->sum('tax_amount'),
            'total_orders' => $query->count(),
            'average_order' => $query->count() > 0 ? $query->sum('total') / $query->count() : 0,
        ];

        return response()->json([
            'type' => $type,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'data' => $data,
            'summary' => $summary,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'external_id' => 'nullable|uuid',
            'currency' => 'nullable|string|max:10',
            'notes' => 'nullable|string',
            'discount_type' => 'nullable|in:fixed,percent',
            'discount_value' => 'nullable|numeric|min:0',
            'loyalty_points_redeemed' => 'nullable|integer|min:0',
            'loyalty_discount' => 'nullable|numeric|min:0',
            'customer_id' => 'nullable|integer',
            'session_id' => 'required|integer|exists:pos_sessions,id',
            'terminal_id' => 'required|integer|exists:pos_terminals,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'nullable|numeric|min:0',
        ]);

        $session = $this->validateSessionIsOpen($validated['session_id'], $validated['terminal_id']);

        return DB::transaction(function () use ($validated) {
            $externalId = $validated['external_id'] ?? (string) Str::uuid();

            $currencySetting = Setting::getValue('currency', ['code' => 'USD']);
            $taxSetting = Setting::getValue('tax', [
                'enabled' => true,
                'pricing_mode' => 'exclusive',
                'default_tax_id' => null,
            ]);

            $taxEnabled = (bool) ($taxSetting['enabled'] ?? true);

            $order = PosOrder::query()->firstOrCreate(
                ['external_id' => $externalId],
                [
                    'order_number' => $this->makeOrderNumber(),
                    'status' => 'draft',
                    'currency' => $validated['currency'] ?? ($currencySetting['code'] ?? 'USD'),
                    'notes' => $validated['notes'] ?? null,
                    'customer_id' => $validated['customer_id'] ?? null,
                    'session_id' => $validated['session_id'] ?? null,
                    'terminal_id' => $validated['terminal_id'] ?? null,
                    'cashier_name' => auth()->user()?->name,
                    'created_by' => TenantUserHelper::resolveTenantUserId(),
                ]
            );

            // Update discount info if provided in the request
            if (isset($validated['discount_type'])) {
                $order->discount_type = $validated['discount_type'];
                $order->discount_value = $validated['discount_value'] ?? 0;
            }

            // Update customer_id if provided and order already exists
            if (isset($validated['customer_id'])) {
                $order->customer_id = $validated['customer_id'];
            }

            // Update notes if provided
            if (isset($validated['notes'])) {
                $order->notes = $validated['notes'];
            }
            
            // Save any changes to discount, customer, or notes
            if ($order->isDirty()) {
                $order->save();
            }

            if ($order->wasRecentlyCreated === false && $order->items()->count() > 0) {
                 // If it's an existing draft, we might want to update it. 
                 // For now, let's assume 'store' is mostly for new drafts or syncing.
                 // If we want to support updating draft items, we'd need to clear old items or update them.
                 // For simplified "sync", we can delete old items and re-add.
                 $order->items()->delete();
            }

            $subtotal = 0.0; // net
            $taxAmount = 0.0;
            $totalAmount = 0.0; // gross

            foreach ($validated['items'] as $line) {
                $product = null;
                $variant = null;

                if (!empty($line['product_variant_id'])) {
                    $variant = ProductVariant::findOrFail($line['product_variant_id']);
                    $product = $variant->product;
                } elseif (!empty($line['product_id'])) {
                    $product = Product::findOrFail($line['product_id']);
                } else {
                    abort(422, 'Each item must have product_id or product_variant_id');
                }

                $qty = (int) $line['quantity'];
                $unitPrice = array_key_exists('unit_price', $line) && $line['unit_price'] !== null
                    ? (float) $line['unit_price']
                    : (float) ($variant ? $variant->price : $product->price);

                $lineSubtotal = $qty * $unitPrice;
                
                // Use effective_tax_rate which includes fallback to default system tax
                $lineTaxRate = (float) ($variant 
                    ? ($variant->tax_rate > 0 ? $variant->tax_rate : $product->effective_tax_rate)
                    : $product->effective_tax_rate);
                    
                if (!$taxEnabled) {
                    $lineTaxRate = 0.0;
                }

                // Use product's effective_tax_is_inclusive to determine calculation mode
                $isInclusive = $product->effective_tax_is_inclusive;

                if ($isInclusive && $lineTaxRate > 0) {
                    $gross = round($lineSubtotal, 2);
                    $div = 1 + ($lineTaxRate / 100);
                    $net = $div > 0 ? round($gross / $div, 2) : $gross;
                    $lineTax = round($gross - $net, 2);
                    $lineSubtotalNet = $net;
                    $lineTotal = $gross;
                } else {
                    $lineSubtotalNet = round($lineSubtotal, 2);
                    $lineTax = round($lineSubtotalNet * ($lineTaxRate / 100), 2);
                    $lineTotal = round($lineSubtotalNet + $lineTax, 2);
                }

                PosOrderItem::create([
                    'pos_order_id' => $order->id,
                    'product_id' => $product?->id,
                    'product_variant_id' => $variant?->id,
                    'name' => $variant ? ($product->name . ' - ' . $variant->name) : $product->name,
                    'sku' => $variant?->sku ?? $product->sku,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'tax_rate' => $lineTaxRate,
                    'tax_amount' => $lineTax,
                    'line_total' => $lineTotal,
                ]);

                $subtotal += $lineSubtotalNet;
                $taxAmount += $lineTax;
                $totalAmount += $lineTotal;
            }

            // Calculate Discount
            $discountAmount = 0.0;
            if ($order->discount_type === 'fixed') {
                $discountAmount = (float) $order->discount_value;
            } elseif ($order->discount_type === 'percent') {
                $discountAmount = $totalAmount * ((float) $order->discount_value / 100);
            }
            $discountAmount = round($discountAmount, 2);

            // Loyalty discount
            $loyaltyDiscount = (float) ($validated['loyalty_discount'] ?? 0);
            $loyaltyPointsRedeemed = (int) ($validated['loyalty_points_redeemed'] ?? 0);

            // Final Total
            $finalTotal = max(0, $totalAmount - $discountAmount - $loyaltyDiscount);

            $order->update([
                'subtotal' => round($subtotal, 2),
                'tax_amount' => round($taxAmount, 2),
                'total' => round($finalTotal, 2),
                'discount_amount' => $discountAmount,
                'loyalty_points_redeemed' => $loyaltyPointsRedeemed,
                'loyalty_discount' => $loyaltyDiscount,
            ]);

            return response()->json($order->load('items', 'payments'), 201);
        });
    }

    public function show($posOrder)
    {
        $posOrder = PosOrder::findOrFail($posOrder);
        
        // Validate session exists and is accessible
        if ($posOrder->session_id) {
            $session = \Modules\POS\Models\PosSession::find($posOrder->session_id);
            if (!$session) {
                return response()->json([
                    'error' => 'Order session not found',
                    'message' => 'This order is associated with a deleted session',
                ], 404);
            }
        }
        
        return response()->json($posOrder->load([
            'customer',
            'terminal',
            'session',
            'createdBy',
            'items.product',
            'items.variant',
            'payments.paymentMethod',
            'payments.receivedBy',
            'journalEntry',
        ]));
    }

    public function pay(Request $request, $posOrder, PosPaymentCompletionService $paymentCompletionService)
    {
        $posOrder = PosOrder::findOrFail($posOrder);

        if ($posOrder->status !== 'draft') {
            return response()->json(['message' => 'Order is not payable'], 422);
        }

        // Validate session is open
        if (!$posOrder->session_id || !$posOrder->terminal_id) {
            return response()->json([
                'error' => 'Order missing session or terminal',
                'message' => 'Cannot process payment without valid session',
            ], 422);
        }
        
        $this->validateSessionIsOpen($posOrder->session_id, $posOrder->terminal_id);

        $validated = $request->validate([
            'method' => 'required|string|max:50',
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($validated, $posOrder, $paymentCompletionService) {
            $amount = (float) $validated['amount'];
            if (abs($amount - (float) $posOrder->total) > 0.01) {
                abort(422, 'Payment amount must equal order total for now.');
            }

            // Set payment state to processing for customer display
            $posOrder->update([
                'payment_state' => 'processing',
                'payment_method' => $validated['method'],
                'amount_tendered' => $amount,
            ]);

            try {
                // Process loyalty points redemption if applicable
                $loyaltyRedemptionResult = null;
                if ($posOrder->loyalty_points_redeemed > 0 && $posOrder->customer_id) {
                    try {
                        $redemptionService = app(RedemptionService::class);
                        $loyaltyRedemptionResult = $redemptionService->redeemForDiscount(
                            $posOrder->customer_id,
                            $posOrder->loyalty_points_redeemed,
                            null, // No invoice ID for POS orders yet
                            'pos_order',
                            $posOrder->id,
                            "pos_redeem_{$posOrder->id}" // Idempotency key
                        );
                    } catch (\Exception $e) {
                        // Log error but don't fail the order
                        Log::warning('Loyalty redemption failed for POS order', [
                            'order_id' => $posOrder->id,
                            'customer_id' => $posOrder->customer_id,
                            'points' => $posOrder->loyalty_points_redeemed,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $posOrder = $paymentCompletionService->complete(
                    $posOrder,
                    $amount,
                    $validated['method'],
                    $validated['reference'] ?? null,
                    TenantUserHelper::resolveTenantUserId(),
                );

                // Calculate change due for cash payments
                $changeDue = 0;
                if (strtolower($validated['method']) === 'cash') {
                    $changeDue = max(0, $amount - (float) $posOrder->total);
                }

                // Set payment state to completed
                $posOrder->update([
                    'payment_state' => 'completed',
                    'change_due' => $changeDue,
                ]);

            } catch (\Exception $e) {
                // Set payment state to failed
                $posOrder->update(['payment_state' => 'failed']);
                throw $e;
            }

            // Notifications
            try {
                // 1. Notify Customer
                if ($posOrder->customer && $posOrder->customer->email) {
                    $posOrder->customer->notify(new CustomerOrderConfirmation($posOrder, 'POS'));
                }

                // 2. Notify Admins
                // Find users with admin role or fallback to first user
                $admins = User::whereHas('roles', function($q) {
                    $q->where('name', 'like', '%admin%'); // Matches admin, super-admin, etc.
                })->get();

                if ($admins->isEmpty()) {
                    // Fallback: Notify the user who created the order if no admins found? No, that's the cashier.
                    // Maybe notify user ID 1?
                    $admin = User::find(1);
                    if ($admin) $admins = collect([$admin]);
                }

                if ($admins->isNotEmpty()) {
                    Notification::send($admins, new AdminOrderAlert($posOrder, 'POS'));
                }
            } catch (\Exception $e) {
                Log::error('POS Notification Failed: ' . $e->getMessage());
            }

            // Award loyalty points for the purchase (after payment)
            $loyaltyEarningResult = null;
            if ($posOrder->customer_id) {
                try {
                    $earningService = app(PointsEarningService::class);
                    $loyaltyEarningResult = $earningService->awardPoints(
                        $posOrder->customer_id,
                        $posOrder->subtotal, // Award points on subtotal (before discounts)
                        'pos_order',
                        $posOrder->id,
                        "Points earned from POS purchase #{$posOrder->order_number}",
                        "pos_earn_{$posOrder->id}" // Idempotency key
                    );
                } catch (\Exception $e) {
                    // Log error but don't fail the order
                    Log::warning('Loyalty points earning failed for POS order', [
                        'order_id' => $posOrder->id,
                        'customer_id' => $posOrder->customer_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $response = $posOrder->fresh()->load('items', 'payments', 'journalEntry', 'customer');
            
            return response()->json([
                'data' => $response,
                'loyalty' => [
                    'redeemed' => $loyaltyRedemptionResult ? [
                        'points' => $loyaltyRedemptionResult['points_redeemed'],
                        'discount' => $loyaltyRedemptionResult['discount_amount'],
                    ] : null,
                    'earned' => $loyaltyEarningResult ? [
                        'points' => $loyaltyEarningResult['points_earned'],
                        'new_balance' => $loyaltyEarningResult['new_balance'],
                        'tier_upgraded' => $loyaltyEarningResult['tier_upgraded'],
                    ] : null,
                ],
            ], 200);
        });
    }

    public function destroy($posOrder)
    {
        $posOrder = PosOrder::findOrFail($posOrder);

        if ($posOrder->status !== 'draft') {
            return response()->json(['message' => 'Only draft orders can be deleted'], 422);
        }

        $posOrder->items()->delete();
        $posOrder->payments()->delete(); // Should be empty for drafts, but safe cleanup
        $posOrder->delete();

        return response()->json(['message' => 'Order deleted successfully']);
    }

    public function cancel(Request $request, $posOrder)
    {
        $posOrder = PosOrder::findOrFail($posOrder);

        if ($posOrder->is_return) {
            return response()->json(['message' => 'Return orders cannot be cancelled'], 422);
        }

        if ($posOrder->status !== 'draft') {
            return response()->json(['message' => 'Only draft orders can be cancelled'], 422);
        }

        $reason = trim((string) $request->input('reason', 'Cancelled from POS orders list'));

        $posOrder->update([
            'status' => 'void',
            'notes' => $this->appendOrderNote($posOrder->notes, 'Cancelled: ' . $reason),
        ]);

        return response()->json([
            'message' => 'Order cancelled successfully',
            'data' => $posOrder->fresh()->load('items', 'payments'),
        ]);
    }

    public function void(Request $request, $posOrder)
    {
        $posOrder = PosOrder::findOrFail($posOrder);

        if ($posOrder->is_return) {
            return response()->json(['message' => 'Return orders cannot be voided'], 422);
        }

        if ($posOrder->status === 'void') {
            return response()->json(['message' => 'Order has already been voided'], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:10|max:500',
            'approval_request_id' => 'nullable|integer|exists:approval_requests,id',
        ]);

        $reason = trim($validated['reason']);
        
        // Check for existing approved request or create new one
        $approvalService = app(\App\Services\ApprovalService::class);
        
        if (isset($validated['approval_request_id'])) {
            // Use existing approval
            $approvalRequest = \App\Models\ApprovalRequest::findOrFail($validated['approval_request_id']);
            
            if (!$approvalRequest->isApproved()) {
                return response()->json([
                    'error' => 'Approval request not approved',
                    'approval_status' => $approvalRequest->status,
                ], 403);
            }
        } else {
            // Create approval request and return it for frontend to handle
            $approvalRequest = $approvalService->createApprovalRequest(
                'void',
                'PosOrder',
                $posOrder->id,
                $posOrder->total,
                $reason
            );
            
            return response()->json([
                'message' => 'Approval required',
                'approval_request' => $approvalRequest,
                'requires_approval' => true,
            ], 202); // 202 Accepted - request created, awaiting approval
        }

        Log::info('POS order void initiated', [
            'order_id' => $posOrder->id,
            'order_number' => $posOrder->order_number,
            'total' => $posOrder->total,
            'status' => $posOrder->status,
            'reason' => $reason,
            'initiated_by' => auth()->id(),
            'initiated_by_name' => auth()->user()->name,
            'approved_by' => $approvalRequest->reviewed_by,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'timestamp' => now()->toIso8601String(),
        ]);

        if ($posOrder->status === 'draft') {
            $posOrder->update([
                'status' => 'void',
                'voided_by' => auth()->id(),
                'voided_at' => now(),
                'void_approved_by' => $approvalRequest->reviewed_by,
                'void_reason' => $reason,
                'notes' => $this->appendOrderNote($posOrder->notes, 'Voided: ' . $reason),
            ]);

            return response()->json([
                'message' => 'Order voided successfully',
                'data' => $posOrder->fresh()->load('items', 'payments'),
            ]);
        }

        if ($posOrder->status !== 'paid') {
            return response()->json(['message' => 'Only draft or paid orders can be voided'], 422);
        }

        if ($posOrder->returns()->exists()) {
            return response()->json(['message' => 'Order already has a return and cannot be voided again'], 422);
        }

        return DB::transaction(function () use ($posOrder, $reason, $approvalRequest) {
            // Create RefundTransaction via RefundService
            $refundService = app(\App\Services\RefundService::class);
            $refundLines = $posOrder->items->map(fn($item) => [
                'line_id' => $item->id,
                'product_id' => $item->product_id,
                'variant_id' => $item->product_variant_id,
                'quantity' => $item->quantity,
                'amount' => $item->line_total,
            ])->toArray();

            $refundTransaction = $refundService->createRefundRequest(
                sourceType: 'PosOrder',
                sourceId: $posOrder->id,
                refundAmount: $posOrder->total,
                originalAmount: $posOrder->total,
                refundLines: $refundLines,
                reason: $reason,
                notes: "Void refund for order #{$posOrder->order_number}",
                idempotencyKey: "void_{$posOrder->id}"
            );

            // Auto-approve and complete the refund
            $refundTransaction = $refundService->approveRefund($refundTransaction->id, $approvalRequest->reviewed_by);
            $refundTransaction = $refundService->completeRefund($refundTransaction->id);

            $returnOrder = $this->createAndRestockReturn(
                $posOrder,
                $this->buildFullReturnItems($posOrder),
                $this->appendOrderNote(null, 'Void refund: ' . $reason)
            );

            PosPayment::create([
                'pos_order_id' => $posOrder->id,
                'method' => 'void_refund',
                'amount' => -$posOrder->total,
                'reference' => "VOID-{$posOrder->order_number}",
                'notes' => $reason,
                'received_by' => $approvalRequest->reviewed_by,
            ]);

            // Reverse loyalty points for voided order
            if ($posOrder->customer_id) {
                try {
                    $reversalService = app(\Modules\Loyalty\Services\PointsReversalService::class);
                    $reversalService->reverseOrder(
                        $posOrder->customer_id,
                        'pos_order',
                        $posOrder->id,
                        "Loyalty reversal for voided order #{$posOrder->order_number}: {$reason}"
                    );
                } catch (\Exception $e) {
                    Log::error('Loyalty reversal failed for voided POS order', [
                        'order_id' => $posOrder->id,
                        'customer_id' => $posOrder->customer_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $posOrder->update([
                'status' => 'void',
                'voided_by' => auth()->id(),
                'voided_at' => now(),
                'void_approved_by' => $approvalRequest->reviewed_by,
                'void_reason' => $reason,
                'notes' => $this->appendOrderNote($posOrder->notes, 'Voided: ' . $reason),
            ]);

            Log::info('POS order void completed', [
                'order_id' => $posOrder->id,
                'order_number' => $posOrder->order_number,
                'return_order_id' => $returnOrder->id,
                'refund_transaction_id' => $refundTransaction->id,
                'timestamp' => now()->toIso8601String(),
            ]);

            return response()->json([
                'message' => 'Order voided successfully',
                'data' => $posOrder->fresh()->load('items', 'payments', 'returns'),
                'return_order' => $returnOrder->load('items'),
                'refund_transaction' => $refundTransaction,
            ]);
        });
    }

    public function refund(Request $request, $posOrder)
    {
        $posOrder = PosOrder::findOrFail($posOrder);

        if ($posOrder->is_return) {
            return response()->json(['message' => 'Return orders cannot be refunded'], 422);
        }

        if ($posOrder->status !== 'paid') {
            return response()->json(['message' => 'Only paid orders can be refunded'], 422);
        }

        if ($posOrder->returns()->exists()) {
            return response()->json(['message' => 'Order already has a refund return'], 422);
        }

        $validated = $request->validate([
            'notes' => 'required|string|min:10|max:500',
            'refund_method' => 'required|in:cash,card,store_credit',
            'approval_request_id' => 'nullable|integer|exists:approval_requests,id',
        ]);

        $notes = trim($validated['notes']);
        
        // Check for existing approved request or create new one
        $approvalService = app(\App\Services\ApprovalService::class);
        
        if (isset($validated['approval_request_id'])) {
            // Use existing approval
            $approvalRequest = \App\Models\ApprovalRequest::findOrFail($validated['approval_request_id']);
            
            if (!$approvalRequest->isApproved()) {
                return response()->json([
                    'error' => 'Approval request not approved',
                    'approval_status' => $approvalRequest->status,
                ], 403);
            }
        } else {
            // Create approval request
            $approvalRequest = $approvalService->createApprovalRequest(
                'refund',
                'PosOrder',
                $posOrder->id,
                $posOrder->total,
                $notes
            );
            
            return response()->json([
                'message' => 'Approval required',
                'approval_request' => $approvalRequest,
                'requires_approval' => true,
            ], 202);
        }

        Log::info('POS order refund initiated', [
            'order_id' => $posOrder->id,
            'order_number' => $posOrder->order_number,
            'total' => $posOrder->total,
            'refund_method' => $validated['refund_method'],
            'notes' => $notes,
            'initiated_by' => auth()->id(),
            'approved_by' => $approvalRequest->reviewed_by,
            'ip_address' => $request->ip(),
            'timestamp' => now()->toIso8601String(),
        ]);

        return DB::transaction(function () use ($posOrder, $notes, $validated, $approvalRequest) {
            // Create RefundTransaction via RefundService
            $refundService = app(\App\Services\RefundService::class);
            $refundLines = $posOrder->items->map(fn($item) => [
                'line_id' => $item->id,
                'product_id' => $item->product_id,
                'variant_id' => $item->product_variant_id,
                'quantity' => $item->quantity,
                'amount' => $item->line_total,
            ])->toArray();

            $refundTransaction = $refundService->createRefundRequest(
                sourceType: 'PosOrder',
                sourceId: $posOrder->id,
                refundAmount: $posOrder->total,
                originalAmount: $posOrder->total,
                refundLines: $refundLines,
                reason: $notes,
                notes: "Full refund for order #{$posOrder->order_number}",
                idempotencyKey: "refund_{$posOrder->id}"
            );

            // Auto-approve and complete the refund
            $refundTransaction = $refundService->approveRefund($refundTransaction->id, $approvalRequest->reviewed_by);
            $refundTransaction = $refundService->completeRefund($refundTransaction->id);

            $returnOrder = $this->createAndRestockReturn(
                $posOrder,
                $this->buildFullReturnItems($posOrder),
                $notes
            );

            PosPayment::create([
                'pos_order_id' => $posOrder->id,
                'method' => $validated['refund_method'],
                'amount' => -$posOrder->total,
                'reference' => "REFUND-{$posOrder->order_number}",
                'notes' => $notes,
                'received_by' => $approvalRequest->reviewed_by,
            ]);

            // CRITICAL: Post GL entry for refund payment
            // This ensures cash drawer reconciliation works correctly
            $postingService = app(\Modules\POS\Services\PosPostingService::class);
            try {
                $refundJournalEntry = $postingService->postRefundPayment(
                    $posOrder,
                    $validated['refund_method'],
                    $posOrder->total,
                    "Refund payment for order #{$posOrder->order_number}"
                );
                
                // Link journal entry to refund transaction
                if ($refundTransaction && $refundJournalEntry) {
                    $refundTransaction->update(['journal_entry_id' => $refundJournalEntry->id]);
                }
            } catch (\Exception $e) {
                Log::error('Failed to post refund payment GL entry', [
                    'order_id' => $posOrder->id,
                    'error' => $e->getMessage(),
                ]);
                // Don't fail the refund, but log the error
            }

            // Reverse loyalty points for refunded order
            if ($posOrder->customer_id) {
                try {
                    $reversalService = app(\Modules\Loyalty\Services\PointsReversalService::class);
                    $reversalService->reverseOrder(
                        $posOrder->customer_id,
                        'pos_order',
                        $posOrder->id,
                        "Loyalty reversal for refunded order #{$posOrder->order_number}: {$notes}"
                    );
                } catch (\Exception $e) {
                    Log::error('Loyalty reversal failed for refunded POS order', [
                        'order_id' => $posOrder->id,
                        'customer_id' => $posOrder->customer_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('POS order refund completed', [
                'order_id' => $posOrder->id,
                'order_number' => $posOrder->order_number,
                'return_order_id' => $returnOrder->id,
                'refund_transaction_id' => $refundTransaction->id,
                'timestamp' => now()->toIso8601String(),
            ]);

            return response()->json([
                'message' => 'Order refunded successfully',
                'return_order' => $returnOrder->load('items'),
                'original_order' => $posOrder->fresh()->load('items', 'returns'),
                'refund_transaction' => $refundTransaction,
            ]);
        });
    }

    /**
     * Create a return/refund for a paid order
     */
    public function createReturn(Request $request, $posOrder)
    {
        $posOrder = PosOrder::findOrFail($posOrder);

        if ($posOrder->status !== 'paid') {
            return response()->json(['message' => 'Only paid orders can be returned'], 422);
        }

        $validated = $request->validate([
            'items'              => 'required|array|min:1',
            'items.*.item_id'    => 'required|exists:pos_order_items,id',
            'items.*.quantity'   => 'required|integer|min:1',
            'notes'              => 'required|string|min:10|max:500',
            'refund_method'      => 'required|in:cash,card,store_credit',
            'approval_request_id' => 'nullable|integer|exists:approval_requests,id',
        ]);

        // Calculate partial return total for approval check
        $returnTotal = collect($validated['items'])->reduce(function (float $sum, array $line) use ($posOrder) {
            $item = $posOrder->items->firstWhere('id', $line['item_id']);
            return $sum + (($item?->unit_price ?? 0) * $line['quantity']);
        }, 0.0);
        
        // Check for existing approved request or create new one
        $approvalService = app(\App\Services\ApprovalService::class);
        
        if (isset($validated['approval_request_id'])) {
            // Use existing approval
            $approvalRequest = \App\Models\ApprovalRequest::findOrFail($validated['approval_request_id']);
            
            if (!$approvalRequest->isApproved()) {
                return response()->json([
                    'error' => 'Approval request not approved',
                    'approval_status' => $approvalRequest->status,
                ], 403);
            }
        } else {
            // Create approval request
            $approvalRequest = $approvalService->createApprovalRequest(
                'return',
                'PosOrder',
                $posOrder->id,
                $returnTotal,
                $validated['notes'],
                null,
                ['items' => $validated['items']]
            );
            
            return response()->json([
                'message' => 'Approval required',
                'approval_request' => $approvalRequest,
                'requires_approval' => true,
            ], 202);
        }

        Log::info('POS order return initiated', [
            'order_id'       => $posOrder->id,
            'order_number'   => $posOrder->order_number,
            'return_total'   => $returnTotal,
            'refund_method'  => $validated['refund_method'],
            'notes'          => $validated['notes'],
            'initiated_by'   => auth()->id(),
            'approved_by'    => $approvalRequest->reviewed_by,
            'ip_address'     => $request->ip(),
            'timestamp'      => now()->toIso8601String(),
        ]);

        return DB::transaction(function () use ($validated, $posOrder, $approvalRequest, $returnTotal) {
            // Create RefundTransaction via RefundService
            $refundService = app(\App\Services\RefundService::class);
            $refundLines = collect($validated['items'])->map(function($line) use ($posOrder) {
                $item = $posOrder->items->firstWhere('id', $line['item_id']);
                return [
                    'line_id' => $line['item_id'],
                    'product_id' => $item->product_id,
                    'variant_id' => $item->product_variant_id,
                    'quantity' => $line['quantity'],
                    'amount' => ($item->unit_price * $line['quantity']),
                ];
            })->toArray();

            $refundTransaction = $refundService->createRefundRequest(
                sourceType: 'PosOrder',
                sourceId: $posOrder->id,
                refundAmount: $returnTotal,
                originalAmount: $posOrder->total,
                refundLines: $refundLines,
                reason: $validated['notes'],
                notes: "Partial return for order #{$posOrder->order_number}",
                idempotencyKey: "return_{$posOrder->id}_" . md5(json_encode($validated['items']))
            );

            // Auto-approve and complete the refund
            $refundTransaction = $refundService->approveRefund($refundTransaction->id, $approvalRequest->reviewed_by);
            $refundTransaction = $refundService->completeRefund($refundTransaction->id);

            $returnOrder = $this->createAndRestockReturn(
                $posOrder,
                $validated['items'],
                $validated['notes'],
            );

            PosPayment::create([
                'pos_order_id' => $posOrder->id,
                'method'       => $validated['refund_method'],
                'amount'       => -$returnTotal,
                'reference'    => "RETURN-{$posOrder->order_number}",
                'notes'        => $validated['notes'],
                'received_by'  => $approvalRequest->reviewed_by,
            ]);

            // TASK POS-010: Proportional loyalty reversal for partial returns
            if ($posOrder->customer_id) {
                try {
                    $reversalService = app(\Modules\Loyalty\Services\PointsReversalService::class);
                    
                    // Execute proportional loyalty reversal
                    $loyaltyResult = $reversalService->reverseProportional(
                        $posOrder->customer_id,
                        'pos_order',
                        $posOrder->id,
                        $returnTotal,
                        $posOrder->total,
                        "Proportional loyalty reversal for partial return of order #{$posOrder->order_number}: {$validated['notes']}",
                        "pos_partial_return_{$posOrder->id}_" . md5(json_encode($validated['items']))
                    );
                    
                    Log::info('Partial return loyalty reversal completed', [
                        'order_id' => $posOrder->id,
                        'customer_id' => $posOrder->customer_id,
                        'return_ratio' => $loyaltyResult['return_ratio'] ?? 0,
                        'return_total' => $returnTotal,
                        'original_total' => $posOrder->total,
                        'earn_reversal' => $loyaltyResult['earn_reversal'] ?? null,
                        'redeem_restoration' => $loyaltyResult['redeem_restoration'] ?? null,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Loyalty reversal failed for partial POS return', [
                        'order_id' => $posOrder->id,
                        'customer_id' => $posOrder->customer_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('POS order return completed', [
                'order_id'        => $posOrder->id,
                'order_number'    => $posOrder->order_number,
                'return_order_id' => $returnOrder->id,
                'refund_amount'   => -$returnTotal,
                'refund_transaction_id' => $refundTransaction->id,
                'timestamp'       => now()->toIso8601String(),
            ]);

            return response()->json([
                'message'        => 'Return created successfully',
                'return_order'   => $returnOrder->load('items'),
                'original_order' => $posOrder->fresh()->load('items', 'returns'),
                'refund_transaction' => $refundTransaction,
            ]);
        });
    }

    /**
     * Generate PDF receipt for a POS order
     */
    public function receipt($posOrder)
    {
        $posOrder = PosOrder::findOrFail($posOrder);
        $posOrder->load(['items.product', 'items.variant', 'payments', 'customer']);

        // Get business settings
        $businessSettings = [
            'business_name' => Setting::getValue('business_name', 'NoovaERP Store'),
            'business_address' => Setting::getValue('business_address', ''),
            'business_phone' => Setting::getValue('business_phone', ''),
        ];

        $pdf = Pdf::loadView('pos::receipt', [
            'order' => $posOrder,
            'settings' => $businessSettings,
        ]);

        // Set paper size to receipt size (80mm thermal paper)
        $pdf->setPaper([0, 0, 226.77, 600], 'portrait'); // 80mm = 226.77 points

        $filename = 'receipt-' . $posOrder->order_number . '.pdf';

        return $pdf->download($filename);
    }

    /**
     * Get or create invoice associated with a POS order
     */
    public function invoice($posOrder)
    {
        $posOrder = PosOrder::findOrFail($posOrder);

        if ($posOrder->status !== 'paid') {
            return response()->json([
                'message' => 'Only paid orders can have invoices',
            ], 422);
        }

        // Try to find existing invoice linked to this POS order via FK
        $invoice = Invoice::where('pos_order_id', $posOrder->id)
            ->with(['customer', 'lines.product'])
            ->first();

        // Auto-create invoice from POS order via SalesOrderService (canonical path)
        if (!$invoice) {
            $invoice = DB::transaction(function () use ($posOrder) {
                // Ensure customer_id exists (walk-in customer for anonymous POS orders)
                if (!$posOrder->customer_id) {
                    $walkIn = \App\Models\Customer::firstOrCreate(
                        ['email' => 'walkin@system.local'],
                        ['name' => 'Walk-in Customer', 'notes' => 'System customer for walk-in POS transactions']
                    );
                    $posOrder->update(['customer_id' => $walkIn->id]);
                }

                /** @var \Modules\Sales\Services\SalesOrderService $salesOrderService */
                $salesOrderService = app(\Modules\Sales\Services\SalesOrderService::class);

                // Create SalesOrder (idempotent via SalesChannelReference)
                $salesOrder = $salesOrderService->createFromPosOrder($posOrder);

                // If already invoiced, retrieve existing invoice
                if ($salesOrder->isInvoiced()) {
                    $link = \Modules\Sales\Models\SalesInvoiceLink::where('sales_order_id', $salesOrder->id)
                        ->where('link_state', 'active')
                        ->first();
                    if ($link) {
                        return \App\Models\Invoice::with(['customer', 'lines.product'])->findOrFail($link->invoice_id);
                    }
                }

                // Fast-track through lifecycle for already-paid POS order
                if ($salesOrder->isDraft()) {
                    $salesOrder = $salesOrderService->confirmOrder($salesOrder);
                }
                if ($salesOrder->isConfirmed()) {
                    $salesOrder = $salesOrderService->allocateOrder($salesOrder);
                }
                if ($salesOrder->isAllocated()) {
                    $salesOrder = $salesOrderService->fulfilOrder($salesOrder);
                }

                // Create invoice via canonical SalesOrderService → InvoiceService path,
                // passing POS totals as overrides so synchronizeInvoiceBookkeeping runs on final amounts.
                $inv = $salesOrderService->createInvoice($salesOrder, [
                    'subtotal'         => $posOrder->subtotal,
                    'tax_amount'       => $posOrder->tax_amount ?? 0,
                    'discount_amount'  => $posOrder->discount_amount ?? 0,
                    'total'            => $posOrder->total,
                    'pos_order_id'     => $posOrder->id,
                    'journal_entry_id' => $posOrder->journal_entry_id,
                ]);

                app(\App\Services\InvoiceService::class)->recordChannelPayment($inv->fresh(), [
                    'payment_date' => now(),
                    'method' => 'card',
                    'reference' => "POS-{$posOrder->order_number}",
                    'amount' => (float) $posOrder->total,
                    'notes' => "POS order {$posOrder->order_number} (pre-paid)",
                    'received_by' => \Modules\OCRBookkeeping\Helpers\TenantUserHelper::requireTenantUserId($posOrder->created_by),
                ]);

                return $inv->load(['customer', 'lines.product']);
            });
        }

        return response()->json([
            'data' => $invoice,
        ]);
    }

    private function makeOrderNumber(): string
    {
        $stamp = now()->format('YmdHis');
        $rand = strtoupper(Str::random(4));
        return 'POS-' . $stamp . '-' . $rand;
    }

    private function buildFullReturnItems(PosOrder $posOrder): array
    {
        return $posOrder->items()
            ->where('quantity', '>', 0)
            ->get()
            ->map(fn (PosOrderItem $item) => [
                'item_id' => $item->id,
                'quantity' => (int) $item->quantity,
            ])
            ->values()
            ->all();
    }

    private function createAndRestockReturn(PosOrder $posOrder, array $items, ?string $notes): PosOrder
    {
        $returnOrder = $posOrder->createReturn($items, $notes);

        $terminal = $posOrder->terminal()->with('warehouse')->first();
        $warehouseId = $terminal?->warehouse_id;

        if (!$warehouseId) {
            Log::warning('POS return has no terminal warehouse — stock restock skipped', [
                'pos_order_id' => $posOrder->id,
            ]);
        }

        if ($warehouseId) {
            $inventoryService = app(InventoryService::class);
            foreach ($returnOrder->items()->with(['product', 'variant'])->get() as $item) {
                $qty = abs($item->quantity);
                $productId = $item->variant ? $item->variant->product_id : $item->product_id;
                $trackInventory = $item->variant
                    ? (bool) $item->variant->track_inventory
                    : (bool) ($item->product?->track_inventory ?? false);

                if ($productId && $trackInventory && $qty > 0) {
                    try {
                        $inventoryService->adjustStock(
                            productId: $productId,
                            warehouseId: $warehouseId,
                            quantity: $qty,
                            type: 'return',
                            reference: 'Return: ' . $returnOrder->order_number,
                            referenceType: 'PosOrder',
                            referenceId: $returnOrder->id,
                        );
                    } catch (\Exception $e) {
                        Log::error('POS stock return failed', [
                            'return_order' => $returnOrder->order_number,
                            'product_id' => $productId,
                            'error' => $e->getMessage(),
                        ]);
                        throw $e;
                    }
                }
            }
        }

        // Post GL reversal for the original order — mirrors WebOrderPostingService::reverseOrderPosting().
        // Must run inside the same DB::transaction as stock restoration (guaranteed by createReturn() wrapper).
        if ($posOrder->journal_entry_id) {
            app(PosPostingService::class)->reverseOrder(
                $posOrder,
                $returnOrder,
                'POS return: ' . ($notes ?? $returnOrder->order_number)
            );
        }

        return $returnOrder;
    }

    private function appendOrderNote(?string $existingNotes, string $note): string
    {
        return collect([$existingNotes, $note])
            ->filter(fn (?string $value) => filled($value))
            ->implode("\n");
    }

    /**
     * Validate that a session exists and is open for processing orders
     */
    private function validateSessionIsOpen(int $sessionId, int $terminalId): \Modules\POS\Models\PosSession
    {
        $session = \Modules\POS\Models\PosSession::find($sessionId);
        
        if (!$session) {
            abort(404, 'Session not found');
        }
        
        if ($session->status !== 'open') {
            abort(422, "Session is {$session->status}, not open. Cannot process orders.");
        }
        
        if ($session->terminal_id !== $terminalId) {
            abort(422, 'Terminal does not match session');
        }
        
        return $session;
    }
}
