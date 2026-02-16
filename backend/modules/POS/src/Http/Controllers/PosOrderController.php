<?php

namespace Modules\POS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Setting;
use App\Models\Tax;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosOrderItem;
use Modules\POS\Models\PosPayment;
use Modules\POS\Services\PosPostingService;
use Modules\Loyalty\Services\RedemptionService;
use Modules\Loyalty\Services\PointsEarningService;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Notifications\AdminOrderAlert;
use App\Notifications\CustomerOrderConfirmation;
use Illuminate\Support\Facades\Notification;
use App\Models\User;

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
            'session_id' => 'nullable|integer',
            'terminal_id' => 'nullable|integer',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'nullable|numeric|min:0',
        ]);

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
                    'created_by' => auth()->id(),
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

    public function show(PosOrder $posOrder)
    {
        return response()->json($posOrder->load('items', 'payments', 'journalEntry'));
    }

    public function pay(Request $request, PosOrder $posOrder, PosPostingService $posting)
    {
        if ($posOrder->status !== 'draft') {
            return response()->json(['message' => 'Order is not payable'], 422);
        }

        $validated = $request->validate([
            'method' => 'required|in:cash,card,bank_transfer,other',
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($validated, $posOrder, $posting) {
            $amount = (float) $validated['amount'];
            if (abs($amount - (float) $posOrder->total) > 0.01) {
                abort(422, 'Payment amount must equal order total for now.');
            }

            // Process loyalty points redemption if applicable
            $loyaltyRedemptionResult = null;
            if ($posOrder->loyalty_points_redeemed > 0 && $posOrder->customer_id) {
                try {
                    $redemptionService = app(RedemptionService::class);
                    $loyaltyRedemptionResult = $redemptionService->redeemForDiscount(
                        $posOrder->customer_id,
                        $posOrder->loyalty_points_redeemed,
                        null // No invoice ID for POS orders yet
                    );
                } catch (\Exception $e) {
                    // Log error but don't fail the order
                    \Log::warning('Loyalty redemption failed for POS order', [
                        'order_id' => $posOrder->id,
                        'customer_id' => $posOrder->customer_id,
                        'points' => $posOrder->loyalty_points_redeemed,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            PosPayment::create([
                'pos_order_id' => $posOrder->id,
                'method' => $validated['method'],
                'amount' => $amount,
                'reference' => $validated['reference'] ?? null,
                'received_by' => auth()->id(),
            ]);

            foreach ($posOrder->items()->with(['product', 'variant'])->get() as $item) {
                if ($item->variant && $item->variant->track_inventory) {
                    $item->variant->adjustStock($item->quantity, 'out', $posOrder->order_number);
                } elseif ($item->product && $item->product->track_inventory) {
                    $item->product->adjustStock($item->quantity, 'out', $posOrder->order_number);
                }
            }

            $entry = $posting->postPaidOrder(
                $posOrder->load('items.variant', 'items.product'),
                $amount,
                $validated['method']
            );

            $posOrder->update([
                'status' => 'paid',
                'paid_at' => now(),
                'journal_entry_id' => $entry->id,
                'receipt_number' => PosOrder::generateReceiptNumber(),
            ]);

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
                \Log::error('POS Notification Failed: ' . $e->getMessage());
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
                        "Points earned from POS purchase #{$posOrder->order_number}"
                    );
                } catch (\Exception $e) {
                    // Log error but don't fail the order
                    \Log::warning('Loyalty points earning failed for POS order', [
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

    public function destroy(PosOrder $posOrder)
    {
        if ($posOrder->status !== 'draft') {
            return response()->json(['message' => 'Only draft orders can be deleted'], 422);
        }

        $posOrder->items()->delete();
        $posOrder->payments()->delete(); // Should be empty for drafts, but safe cleanup
        $posOrder->delete();

        return response()->json(['message' => 'Order deleted successfully']);
    }

    /**
     * Create a return/refund for a paid order
     */
    public function createReturn(Request $request, PosOrder $posOrder)
    {
        if ($posOrder->status !== 'paid') {
            return response()->json(['message' => 'Only paid orders can be returned'], 422);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:pos_order_items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string',
            'refund_method' => 'nullable|in:cash,card,store_credit',
        ]);

        return DB::transaction(function () use ($validated, $posOrder) {
            $returnOrder = $posOrder->createReturn($validated['items'], $validated['notes']);

            // Process stock returns
            foreach ($returnOrder->items()->with(['product', 'variant'])->get() as $item) {
                $qty = abs($item->quantity); // Returns are stored as negative
                
                if ($item->variant && $item->variant->track_inventory) {
                    $item->variant->adjustStock($qty, 'in', 'Return: ' . $returnOrder->order_number);
                } elseif ($item->product && $item->product->track_inventory) {
                    $item->product->adjustStock($qty, 'in', 'Return: ' . $returnOrder->order_number);
                }
            }

            return response()->json([
                'message' => 'Return created successfully',
                'return_order' => $returnOrder->load('items'),
                'original_order' => $posOrder->fresh()->load('items', 'returns'),
            ]);
        });
    }

    /**
     * Generate PDF receipt for a POS order
     */
    public function receipt(PosOrder $posOrder)
    {
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
     * Get invoice associated with a POS order
     */
    public function invoice(PosOrder $posOrder)
    {
        // Find invoice by searching notes for the order number
        $invoice = Invoice::where('notes', 'like', "%POS Order: {$posOrder->order_number}%")
            ->with(['customer', 'lines.product'])
            ->first();

        if (!$invoice) {
            return response()->json([
                'message' => 'No invoice found for this order',
            ], 404);
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
}
