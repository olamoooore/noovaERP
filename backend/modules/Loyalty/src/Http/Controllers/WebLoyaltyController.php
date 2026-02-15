<?php

namespace Modules\Loyalty\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Loyalty\Models\CustomerLoyalty;
use Modules\Loyalty\Models\LoyaltyTransaction;
use Modules\Loyalty\Services\PointsCalculationService;
use Modules\Ecommerce\Http\Middleware\WebCustomerAuth;

class WebLoyaltyController extends Controller
{
    public function __construct(
        private PointsCalculationService $calculationService
    ) {}

    /**
     * Get loyalty balance and tier info
     */
    public function balance(Request $request): JsonResponse
    {
        $customer = $request->attributes->get('web_customer');
        
        if (!$customer) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $loyalty = CustomerLoyalty::with('tier', 'program')
            ->where('customer_id', $customer->id)
            ->first();

        if (!$loyalty) {
            return response()->json([
                'enrolled' => false,
                'points' => 0,
            ]);
        }

        // Calculate progress to next tier
        $nextTier = null;
        $progress = null;
        
        if ($loyalty->tier) {
            $nextTier = $loyalty->program->tiers()
                ->where('sort_order', '>', $loyalty->tier->sort_order)
                ->orderBy('sort_order', 'asc')
                ->first();

            if ($nextTier) {
                $currentThreshold = $loyalty->tier->points_threshold ?? 0;
                $nextThreshold = $nextTier->points_threshold ?? 0;
                $pointsRange = $nextThreshold - $currentThreshold;
                
                // Avoid division by zero
                if ($pointsRange > 0) {
                    $progress = [
                        'current' => $loyalty->lifetime_points - $currentThreshold,
                        'needed' => $pointsRange,
                        'percent' => min(100, round((($loyalty->lifetime_points - $currentThreshold) / $pointsRange) * 100)),
                    ];
                } else {
                    $progress = [
                        'current' => $loyalty->lifetime_points - $currentThreshold,
                        'needed' => 0,
                        'percent' => 100,
                    ];
                }
            }
        }

        return response()->json([
            'enrolled' => true,
            'points' => [
                'current' => $loyalty->current_points,
                'lifetime' => $loyalty->lifetime_points,
                'earned' => $loyalty->points_earned,
                'redeemed' => $loyalty->points_redeemed,
            ],
            'tier' => $loyalty->tier ? [
                'id' => $loyalty->tier->id,
                'name' => $loyalty->tier->name,
                'color' => $loyalty->tier->color,
                'multiplier' => $loyalty->tier->earning_multiplier,
                'icon' => $loyalty->tier->icon,
            ] : null,
            'next_tier' => $nextTier ? [
                'name' => $nextTier->name,
                'points_needed' => $nextTier->points_threshold - $loyalty->lifetime_points,
            ] : null,
            'progress' => $progress,
            'program' => $loyalty->program ? [
                'min_redemption_points' => $loyalty->program->min_redemption_points,
                'max_redemption_percent' => $loyalty->program->max_redemption_percent,
                'currency_per_point' => $loyalty->program->currency_per_point,
            ] : null,
        ]);
    }

    /**
     * Get loyalty transaction history
     */
    public function history(Request $request): JsonResponse
    {
        $customer = $request->attributes->get('web_customer');
        
        if (!$customer) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $loyalty = CustomerLoyalty::where('customer_id', $customer->id)->first();

        if (!$loyalty) {
            return response()->json(['transactions' => []]);
        }

        $transactions = LoyaltyTransaction::where('customer_loyalty_id', $loyalty->id)
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'transactions' => $transactions->map(fn($t) => [
                'id' => $t->id,
                'type' => $t->type,
                'points' => $t->points,
                'balance_after' => $t->balance_after,
                'description' => $t->description,
                'reference_type' => $t->reference_type,
                'reference_id' => $t->reference_id,
                'created_at' => $t->created_at,
                'expires_at' => $t->expires_at,
            ]),
            'pagination' => [
                'current_page' => $transactions->currentPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }

    /**
     * Calculate discount for points
     */
    public function calculate(Request $request): JsonResponse
    {
        $customer = $request->attributes->get('web_customer');
        
        if (!$customer) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'points' => 'required|integer|min:1',
            'order_amount' => 'required|numeric|min:0',
        ]);

        $loyalty = CustomerLoyalty::where('customer_id', $customer->id)->first();

        if (!$loyalty) {
            return response()->json(['error' => 'Not enrolled in loyalty program'], 400);
        }

        // Check if can redeem
        $canRedeem = $this->calculationService->canRedeemPoints(
            $validated['points'],
            $customer->id
        );

        if (!$canRedeem['can_redeem']) {
            return response()->json([
                'can_redeem' => false,
                'reason' => $canRedeem['reason'],
            ]);
        }

        // Calculate discount
        $discount = $this->calculationService->calculateDiscountFromPoints($validated['points']);
        
        // Check max redemption
        $maxPoints = $this->calculationService->calculateMaxRedeemablePoints(
            $validated['order_amount'],
            $loyalty->current_points
        );

        return response()->json([
            'can_redeem' => true,
            'points' => $validated['points'],
            'discount_amount' => $discount,
            'max_points' => $maxPoints,
            'new_balance' => $loyalty->current_points - $validated['points'],
        ]);
    }
}
