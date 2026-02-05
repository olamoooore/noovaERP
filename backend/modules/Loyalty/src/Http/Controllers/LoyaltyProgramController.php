<?php

namespace Modules\Loyalty\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Loyalty\Models\LoyaltyProgram;
use Modules\Loyalty\Models\LoyaltyTier;

class LoyaltyProgramController extends Controller
{
    /**
     * Get active loyalty program
     */
    public function index()
    {
        $program = LoyaltyProgram::with('tiers')
            ->where('is_active', true)
            ->first();

        if (!$program) {
            return response()->json([
                'message' => 'No active loyalty program found',
            ], 404);
        }

        return response()->json($program);
    }

    /**
     * Get all loyalty programs (admin)
     */
    public function all()
    {
        $programs = LoyaltyProgram::with('tiers')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($programs);
    }

    /**
     * Get program details
     */
    public function show(int $programId)
    {
        $program = LoyaltyProgram::with('tiers')->findOrFail($programId);
        return response()->json($program);
    }

    /**
     * Create new loyalty program (admin)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'points_per_currency' => 'required|numeric|min:0',
            'currency_per_point' => 'required|numeric|min:0',
            'min_redemption_points' => 'required|integer|min:1',
            'max_redemption_percent' => 'required|numeric|min:0|max:100',
            'points_expiry_months' => 'required|integer|min:1',
            'is_active' => 'boolean',
        ]);

        $program = LoyaltyProgram::create($validated);

        return response()->json($program, 201);
    }

    /**
     * Update loyalty program (admin)
     */
    public function update(Request $request, int $programId)
    {
        $program = LoyaltyProgram::findOrFail($programId);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'points_per_currency' => 'sometimes|numeric|min:0',
            'currency_per_point' => 'sometimes|numeric|min:0',
            'min_redemption_points' => 'sometimes|integer|min:1',
            'max_redemption_percent' => 'sometimes|numeric|min:0|max:100',
            'points_expiry_months' => 'sometimes|integer|min:1',
            'is_active' => 'boolean',
        ]);

        $program->update($validated);

        return response()->json($program);
    }

    /**
     * Get all tiers for a program
     */
    public function tiers(int $programId)
    {
        $tiers = LoyaltyTier::where('loyalty_program_id', $programId)
            ->orderBy('sort_order')
            ->get();

        return response()->json($tiers);
    }

    /**
     * Create new tier for a program (admin)
     */
    public function storeTier(Request $request, int $programId)
    {
        $program = LoyaltyProgram::findOrFail($programId);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'min_points' => 'required|integer|min:0',
            'max_points' => 'nullable|integer|min:0',
            'earning_multiplier' => 'required|numeric|min:0',
            'discount_percent' => 'required|numeric|min:0|max:100',
            'benefits' => 'nullable|array',
            'benefits.*' => 'string',
            'color' => 'nullable|string|max:7',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $validated['loyalty_program_id'] = $programId;
        
        // Set default sort_order if not provided
        if (!isset($validated['sort_order'])) {
            $maxOrder = LoyaltyTier::where('loyalty_program_id', $programId)->max('sort_order') ?? 0;
            $validated['sort_order'] = $maxOrder + 1;
        }

        $tier = LoyaltyTier::create($validated);

        return response()->json($tier, 201);
    }

    /**
     * Update tier (admin)
     */
    public function updateTier(Request $request, int $tierId)
    {
        $tier = LoyaltyTier::findOrFail($tierId);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'min_points' => 'sometimes|integer|min:0',
            'max_points' => 'nullable|integer|min:0',
            'earning_multiplier' => 'sometimes|numeric|min:0',
            'discount_percent' => 'sometimes|numeric|min:0|max:100',
            'benefits' => 'nullable|array',
            'benefits.*' => 'string',
            'color' => 'nullable|string|max:7',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        $tier->update($validated);

        return response()->json($tier);
    }

    /**
     * Delete tier (admin)
     */
    public function destroyTier(int $tierId)
    {
        $tier = LoyaltyTier::findOrFail($tierId);
        
        // Check if any customers are assigned to this tier
        $customersCount = \Modules\Loyalty\Models\CustomerLoyalty::where('loyalty_tier_id', $tierId)->count();
        
        if ($customersCount > 0) {
            return response()->json([
                'message' => 'Cannot delete tier. ' . $customersCount . ' customers are assigned to this tier.',
            ], 422);
        }

        $tier->delete();

        return response()->json(['message' => 'Tier deleted successfully']);
    }

    /**
     * Get program statistics
     */
    public function statistics(int $programId)
    {
        $program = LoyaltyProgram::findOrFail($programId);

        // Total enrolled customers
        $totalCustomers = \Modules\Loyalty\Models\CustomerLoyalty::where('loyalty_program_id', $programId)->count();

        // Total points issued
        $totalPointsIssued = \Modules\Loyalty\Models\CustomerLoyalty::where('loyalty_program_id', $programId)
            ->sum('lifetime_points');

        // Total points redeemed
        $totalPointsRedeemed = \Modules\Loyalty\Models\CustomerLoyalty::where('loyalty_program_id', $programId)
            ->sum('points_redeemed');

        // Active points balance
        $activePoints = \Modules\Loyalty\Models\CustomerLoyalty::where('loyalty_program_id', $programId)
            ->sum('current_points');

        // Customers by tier
        $customersByTier = \Modules\Loyalty\Models\CustomerLoyalty::with('tier')
            ->where('loyalty_program_id', $programId)
            ->get()
            ->groupBy('loyalty_tier_id')
            ->map(function ($group) {
                return [
                    'tier' => $group->first()->tier,
                    'count' => $group->count(),
                ];
            })
            ->values();

        // Recent enrollments (last 30 days)
        $recentEnrollments = \Modules\Loyalty\Models\CustomerLoyalty::where('loyalty_program_id', $programId)
            ->where('joined_at', '>=', now()->subDays(30))
            ->count();

        return response()->json([
            'program' => $program,
            'total_customers' => $totalCustomers,
            'total_points_issued' => $totalPointsIssued,
            'total_points_redeemed' => $totalPointsRedeemed,
            'active_points_balance' => $activePoints,
            'customers_by_tier' => $customersByTier,
            'recent_enrollments' => $recentEnrollments,
        ]);
    }
}
