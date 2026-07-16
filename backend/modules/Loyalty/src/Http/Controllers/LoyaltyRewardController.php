<?php

namespace Modules\Loyalty\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Loyalty\Models\LoyaltyReward;

class LoyaltyRewardController extends Controller
{
    /**
     * Get all active rewards
     */
    public function index(Request $request)
    {
        $query = LoyaltyReward::with('program')
            ->where('is_active', true)
            ->orderBy('points_cost', 'asc');

        // Filter by program
        if ($request->has('program_id') && $request->program_id) {
            $query->where('loyalty_program_id', $request->program_id);
        }

        // Filter by points range
        if ($request->has('min_points') && $request->min_points) {
            $query->where('points_cost', '>=', $request->min_points);
        }
        if ($request->has('max_points') && $request->max_points) {
            $query->where('points_cost', '<=', $request->max_points);
        }

        // Only show in-stock rewards
        if ($request->has('in_stock') && $request->in_stock) {
            $query->where(function ($q) {
                $q->whereNull('stock_quantity')
                    ->orWhere('stock_quantity', '>', 0);
            });
        }

        $perPage = $request->input('per_page', 20);

        return $query->paginate($perPage);
    }

    /**
     * Get reward details
     */
    public function show(int $rewardId)
    {
        $reward = LoyaltyReward::with('program')->findOrFail($rewardId);

        return response()->json($reward);
    }

    /**
     * Create new reward (admin)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'loyalty_program_id' => 'required|integer|exists:loyalty_programs,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'points_cost' => 'required|integer|min:1',
            'stock_quantity' => 'nullable|integer|min:0',
            'image_url' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        $reward = LoyaltyReward::create($validated);

        return response()->json($reward, 201);
    }

    /**
     * Update reward (admin)
     */
    public function update(Request $request, int $rewardId)
    {
        $reward = LoyaltyReward::findOrFail($rewardId);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'points_cost' => 'sometimes|integer|min:1',
            'stock_quantity' => 'nullable|integer|min:0',
            'image_url' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        $reward->update($validated);

        return response()->json($reward);
    }

    /**
     * Delete reward (admin)
     */
    public function destroy(int $rewardId)
    {
        $reward = LoyaltyReward::findOrFail($rewardId);
        $reward->delete();

        return response()->json(['message' => 'Reward deleted successfully']);
    }

    /**
     * Get rewards catalog for customer
     */
    public function catalog(Request $request, int $customerId)
    {
        $customerLoyalty = \Modules\Loyalty\Models\CustomerLoyalty::where('customer_id', $customerId)
            ->firstOrFail();

        $rewards = LoyaltyReward::where('loyalty_program_id', $customerLoyalty->loyalty_program_id)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('stock_quantity')
                    ->orWhere('stock_quantity', '>', 0);
            })
            ->orderBy('points_cost', 'asc')
            ->get()
            ->map(function ($reward) use ($customerLoyalty) {
                $reward->can_afford = $customerLoyalty->current_points >= $reward->points_cost;

                return $reward;
            });

        return response()->json([
            'customer_points' => $customerLoyalty->current_points,
            'data' => $rewards,
        ]);
    }
}
