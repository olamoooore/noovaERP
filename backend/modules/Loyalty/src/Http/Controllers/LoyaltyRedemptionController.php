<?php

namespace Modules\Loyalty\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Loyalty\Models\LoyaltyRedemption;
use Modules\Loyalty\Services\PointsCalculationService;
use Modules\Loyalty\Services\RedemptionService;

class LoyaltyRedemptionController extends Controller
{
    public function __construct(
        private RedemptionService $redemptionService,
        private PointsCalculationService $pointsCalculation
    ) {}

    /**
     * Get all redemptions (admin)
     */
    public function index(Request $request)
    {
        $query = LoyaltyRedemption::with([
            'customerLoyalty.customer',
            'reward',
        ])->orderBy('created_at', 'desc');

        // Filter by status
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Filter by type
        if ($request->has('type') && $request->type) {
            $query->where('type', $request->type);
        }

        // Search by customer name
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->whereHas('customerLoyalty.customer', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $perPage = $request->input('per_page', 20);

        return $query->paginate($perPage);
    }

    /**
     * Redeem points for discount
     */
    public function redeemForDiscount(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'points' => 'required|integer|min:1',
            'invoice_id' => 'nullable|integer|exists:invoices,id',
        ]);

        try {
            $result = $this->redemptionService->redeemForDiscount(
                $validated['customer_id'],
                $validated['points'],
                $validated['invoice_id'] ?? null
            );

            return response()->json($result, 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Redeem points for a reward
     */
    public function redeemForReward(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'reward_id' => 'required|integer|exists:loyalty_rewards,id',
        ]);

        try {
            $result = $this->redemptionService->redeemForReward(
                $validated['customer_id'],
                $validated['reward_id']
            );

            return response()->json($result, 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Calculate redemption preview
     */
    public function calculateRedemption(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'points' => 'required|integer|min:1',
            'invoice_amount' => 'nullable|numeric|min:0',
        ]);

        $customerId = $validated['customer_id'];
        $points = $validated['points'];

        // Check if can redeem
        $validation = $this->pointsCalculation->canRedeemPoints($points, $customerId);

        if (! $validation['can_redeem']) {
            return response()->json([
                'can_redeem' => false,
                'reason' => $validation['reason'],
            ], 422);
        }

        // Calculate discount
        $discountAmount = $this->pointsCalculation->calculateDiscountFromPoints($points);

        // If invoice amount provided, calculate max redeemable
        $maxRedeemable = null;
        if (isset($validated['invoice_amount'])) {
            $customerLoyalty = \Modules\Loyalty\Models\CustomerLoyalty::where('customer_id', $customerId)->first();
            $maxRedeemable = $this->pointsCalculation->calculateMaxRedeemablePoints(
                $validated['invoice_amount'],
                $customerLoyalty->current_points
            );
        }

        return response()->json([
            'can_redeem' => true,
            'points' => $points,
            'discount_amount' => $discountAmount,
            'max_redeemable_points' => $maxRedeemable,
        ]);
    }

    /**
     * Get POS redemption info for a customer (available points, max redeemable, etc.)
     */
    public function posRedemptionInfo(Request $request, int $customerId)
    {
        $customerLoyalty = \Modules\Loyalty\Models\CustomerLoyalty::with('tier')
            ->where('customer_id', $customerId)
            ->first();

        if (! $customerLoyalty) {
            return response()->json([
                'enrolled' => false,
                'message' => 'Customer not enrolled in loyalty program',
            ]);
        }

        $program = \Modules\Loyalty\Models\LoyaltyProgram::where('is_active', true)->first();

        if (! $program) {
            return response()->json([
                'enrolled' => false,
                'message' => 'No active loyalty program',
            ]);
        }

        $invoiceAmount = $request->input('invoice_amount', 0);
        $availablePoints = $customerLoyalty->current_points;

        // Calculate max redeemable points for this invoice amount
        $maxRedeemable = 0;
        if ($invoiceAmount > 0) {
            $maxRedeemable = $this->pointsCalculation->calculateMaxRedeemablePoints(
                $invoiceAmount,
                $availablePoints,
                $program->id
            );
        }

        // Calculate discount value for max redeemable points
        $maxDiscountAmount = $this->pointsCalculation->calculateDiscountFromPoints($maxRedeemable, $program->id);

        return response()->json([
            'enrolled' => true,
            'available_points' => $availablePoints,
            'tier_name' => $customerLoyalty->tier?->name ?? 'Bronze',
            'tier_multiplier' => $customerLoyalty->tier?->earning_multiplier ?? 1.0,
            'currency_per_point' => $program->currency_per_point,
            'min_redemption_points' => $program->min_redemption_points,
            'max_redemption_percent' => $program->max_redemption_percent,
            'max_redeemable_points' => $maxRedeemable,
            'max_discount_amount' => $maxDiscountAmount,
            'invoice_amount' => $invoiceAmount,
        ]);
    }

    /**
     * Get customer's redemption history
     */
    public function history(Request $request, int $customerId)
    {
        $customerLoyalty = \Modules\Loyalty\Models\CustomerLoyalty::where('customer_id', $customerId)
            ->firstOrFail();

        $query = LoyaltyRedemption::with(['reward', 'transaction'])
            ->where('customer_loyalty_id', $customerLoyalty->id)
            ->orderBy('created_at', 'desc');

        // Filter by status
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        $perPage = $request->input('per_page', 20);

        return $query->paginate($perPage);
    }

    /**
     * Cancel a redemption
     */
    public function cancel(Request $request, int $redemptionId)
    {
        try {
            $result = $this->redemptionService->cancelRedemption($redemptionId);

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get redemption details
     */
    public function show(int $redemptionId)
    {
        $redemption = LoyaltyRedemption::with([
            'customerLoyalty.customer',
            'reward',
            'transaction',
            'invoice',
        ])->findOrFail($redemptionId);

        return response()->json($redemption);
    }

    /**
     * Update redemption status (admin)
     */
    public function updateStatus(Request $request, int $redemptionId)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,completed,cancelled',
        ]);

        $redemption = LoyaltyRedemption::findOrFail($redemptionId);

        // Only allow status updates for pending redemptions
        if ($redemption->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Can only update status of pending redemptions',
            ], 422);
        }

        $redemption->update([
            'status' => $validated['status'],
            'completed_at' => $validated['status'] === 'completed' ? now() : null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Redemption status updated successfully',
            'redemption' => $redemption,
        ]);
    }
}
