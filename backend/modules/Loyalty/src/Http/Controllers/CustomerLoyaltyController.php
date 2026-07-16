<?php

namespace Modules\Loyalty\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Modules\Loyalty\Models\CustomerLoyalty;
use Modules\Loyalty\Models\LoyaltyProgram;
use Modules\Loyalty\Models\LoyaltyTier;
use Modules\Loyalty\Models\LoyaltyTransaction;
use Modules\Loyalty\Services\PointsEarningService;
use Modules\Loyalty\Services\TierUpgradeService;

class CustomerLoyaltyController extends Controller
{
    public function __construct(
        private PointsEarningService $earningService,
        private TierUpgradeService $tierService
    ) {}

    /**
     * Get all customers with their loyalty accounts (including non-enrolled)
     */
    public function index(Request $request)
    {
        $search = $request->input('search');

        // Query all customers, left join with loyalty data
        $query = Customer::query()
            ->select([
                'customers.id as customer_id',
                'customers.name as customer_name',
                'customers.email as customer_email',
                'customers.phone as customer_phone',
                'customer_loyalty.id as loyalty_id',
                'customer_loyalty.current_points',
                'customer_loyalty.lifetime_points',
                'customer_loyalty.points_redeemed',
                'customer_loyalty.joined_at',
                'loyalty_tiers.name as tier_name',
                'loyalty_tiers.color as tier_color',
            ])
            ->leftJoin('customer_loyalty', 'customers.id', '=', 'customer_loyalty.customer_id')
            ->leftJoin('loyalty_tiers', 'customer_loyalty.loyalty_tier_id', '=', 'loyalty_tiers.id');

        // Search by customer name or email
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('customers.name', 'like', "%{$search}%")
                    ->orWhere('customers.email', 'like', "%{$search}%")
                    ->orWhere('customers.phone', 'like', "%{$search}%");
            });
        }

        $customers = $query->orderBy('customers.name')->paginate(20);

        // Format response
        $data = $customers->map(function ($customer) {
            return [
                'id' => $customer->loyalty_id,
                'customer_id' => $customer->customer_id,
                'customer_name' => $customer->customer_name,
                'customer_email' => $customer->customer_email,
                'customer_phone' => $customer->customer_phone,
                'tier_name' => $customer->tier_name ?? 'Not Enrolled',
                'tier_color' => $customer->tier_color ?? '#9CA3AF',
                'current_points' => $customer->current_points ?? 0,
                'lifetime_points' => $customer->lifetime_points ?? 0,
                'points_redeemed' => $customer->points_redeemed ?? 0,
                'is_enrolled' => ! is_null($customer->loyalty_id),
                'joined_at' => $customer->joined_at,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
            ],
        ]);
    }

    /**
     * Enroll a customer in the loyalty program
     */
    public function enroll(Request $request, int $customerId)
    {
        // Check if already enrolled
        $existing = CustomerLoyalty::where('customer_id', $customerId)->first();
        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'Customer is already enrolled in the loyalty program',
            ], 422);
        }

        // Get active program
        $program = LoyaltyProgram::where('is_active', true)->first();
        if (! $program) {
            return response()->json([
                'success' => false,
                'message' => 'No active loyalty program found',
            ], 422);
        }

        // Get starting tier (lowest sort_order)
        $startingTier = LoyaltyTier::where('loyalty_program_id', $program->id)
            ->orderBy('sort_order', 'asc')
            ->first();

        // Create loyalty account
        $customerLoyalty = CustomerLoyalty::create([
            'customer_id' => $customerId,
            'loyalty_program_id' => $program->id,
            'loyalty_tier_id' => $startingTier?->id,
            'current_points' => 0,
            'lifetime_points' => 0,
            'points_earned' => 0,
            'points_redeemed' => 0,
            'points_expired' => 0,
            'joined_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Customer enrolled successfully',
            'data' => $customerLoyalty->load(['customer', 'tier']),
        ]);
    }

    /**
     * Get customer loyalty information
     */
    public function show(Request $request, int $customerId)
    {
        $customerLoyalty = CustomerLoyalty::with(['customer', 'program', 'tier'])
            ->where('customer_id', $customerId)
            ->first();

        if (! $customerLoyalty) {
            return response()->json([
                'enrolled' => false,
                'message' => 'Customer not enrolled in loyalty program',
            ], 404);
        }

        // Get next tier info
        $nextTierInfo = $this->tierService->getNextTierInfo($customerLoyalty->id);

        return response()->json([
            'enrolled' => true,
            'customer_loyalty' => $customerLoyalty,
            'next_tier' => $nextTierInfo,
        ]);
    }

    /**
     * Get customer's loyalty transactions
     */
    public function transactions(Request $request, int $customerId)
    {
        $customerLoyalty = CustomerLoyalty::where('customer_id', $customerId)->firstOrFail();

        $query = LoyaltyTransaction::where('customer_loyalty_id', $customerLoyalty->id)
            ->orderBy('created_at', 'desc');

        // Filter by type
        if ($request->has('type') && $request->type) {
            $query->where('type', $request->type);
        }

        // Filter by date range
        if ($request->has('start_date') && $request->start_date) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }
        if ($request->has('end_date') && $request->end_date) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        $perPage = $request->input('per_page', 20);
        $transactions = $query->paginate($perPage);

        return response()->json($transactions);
    }

    /**
     * Award points to customer (from invoice/payment)
     */
    public function earnPoints(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'amount' => 'required|numeric|min:0.01',
            'reference_type' => 'nullable|string',
            'reference_id' => 'nullable|integer',
            'description' => 'nullable|string',
        ]);

        try {
            $result = $this->earningService->awardPoints(
                $validated['customer_id'],
                $validated['amount'],
                $validated['reference_type'] ?? null,
                $validated['reference_id'] ?? null,
                $validated['description'] ?? null
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
     * Manually adjust customer points (admin only)
     */
    public function adjustPoints(Request $request, int $customerId)
    {
        $validated = $request->validate([
            'points' => 'required|integer',
            'reason' => 'required|string|max:255',
        ]);

        try {
            $result = $this->earningService->adjustPoints(
                $customerId,
                $validated['points'],
                $validated['reason']
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get customer loyalty summary/dashboard
     */
    public function dashboard(Request $request, int $customerId)
    {
        $customerLoyalty = CustomerLoyalty::with(['customer', 'program', 'tier'])
            ->where('customer_id', $customerId)
            ->firstOrFail();

        // Recent transactions
        $recentTransactions = LoyaltyTransaction::where('customer_loyalty_id', $customerLoyalty->id)
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        // Points expiring soon (next 30 days)
        $expiringPoints = LoyaltyTransaction::where('customer_loyalty_id', $customerLoyalty->id)
            ->where('type', 'earn')
            ->where('expires_at', '<=', now()->addDays(30))
            ->where('expires_at', '>', now())
            ->sum('points');

        // Next tier info
        $nextTierInfo = $this->tierService->getNextTierInfo($customerLoyalty->id);

        // Monthly points earned (last 12 months)
        $monthlyPoints = LoyaltyTransaction::where('customer_loyalty_id', $customerLoyalty->id)
            ->where('type', 'earn')
            ->where('created_at', '>=', now()->subMonths(12))
            ->selectRaw('EXTRACT(YEAR FROM created_at) as year, EXTRACT(MONTH FROM created_at) as month, SUM(points) as total')
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get();

        return response()->json([
            'customer_loyalty' => $customerLoyalty,
            'recent_transactions' => $recentTransactions,
            'expiring_points' => $expiringPoints,
            'next_tier' => $nextTierInfo,
            'monthly_points' => $monthlyPoints,
        ]);
    }
}
