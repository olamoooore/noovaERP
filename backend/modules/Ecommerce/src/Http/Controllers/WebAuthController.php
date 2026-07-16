<?php

namespace Modules\Ecommerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Ecommerce\Models\WebCustomerSession;
use Modules\Ecommerce\Models\WebOrder;

class WebAuthController extends Controller
{
    /**
     * Register a new customer
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:8|confirmed',
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'phone' => 'nullable|string|max:50',
            'guest_token' => 'nullable|string',
        ]);

        // Check if customer already exists
        $existing = Customer::where('email', $validated['email'])->first();
        if ($existing) {
            // If the customer has a password set, they already registered properly
            if ($existing->password) {
                return response()->json([
                    'message' => 'An account with this email already exists. Please login instead.',
                    'errors' => ['email' => ['An account with this email already exists. Please login instead.']],
                ], 422);
            }

            // Customer exists without password (legacy/orphaned) — update their record
            $existing->update([
                'name' => $validated['first_name'].' '.$validated['last_name'],
                'phone' => $validated['phone'] ?? $existing->phone,
                'password' => Hash::make($validated['password']),
            ]);
            $customer = $existing;
        } else {
            // Create new customer
            $customerData = [
                'name' => $validated['first_name'].' '.$validated['last_name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'is_active' => true,
                'password' => Hash::make($validated['password']),
            ];

            // Only set 'type' if the column exists (added by enhancement migration)
            if (\Schema::hasColumn('customers', 'type')) {
                $customerData['type'] = 'individual';
            }

            $customer = Customer::create($customerData);
        }

        // Link guest orders by token
        if (! empty($validated['guest_token'])) {
            WebOrder::where('guest_token', $validated['guest_token'])
                ->whereNull('customer_id')
                ->update(['customer_id' => $customer->id]);
        }

        // Also link any orders with matching email
        WebOrder::where('email', $validated['email'])
            ->whereNull('customer_id')
            ->update(['customer_id' => $customer->id]);

        // Auto-enroll customer in loyalty program
        $this->autoEnrollInLoyalty($customer);

        // Create session
        $session = $this->createSession($customer);

        return response()->json([
            'success' => true,
            'token' => $session->token,
            'customer' => $this->formatCustomerResponse($customer),
        ], 201);
    }

    /**
     * Auto-enroll customer in loyalty program if one exists
     */
    private function autoEnrollInLoyalty(Customer $customer): void
    {
        try {
            // Check if customer is already enrolled
            $existingLoyalty = \Modules\Loyalty\Models\CustomerLoyalty::where('customer_id', $customer->id)->first();
            if ($existingLoyalty) {
                return; // Already enrolled
            }

            // Get active loyalty program
            $program = \Modules\Loyalty\Models\LoyaltyProgram::where('is_active', true)->first();
            if (! $program) {
                return; // No active program to enroll in
            }

            // Get starting tier (lowest sort_order)
            $startingTier = $program->tiers()
                ->orderBy('sort_order', 'asc')
                ->first();

            // Create loyalty account
            \Modules\Loyalty\Models\CustomerLoyalty::create([
                'customer_id' => $customer->id,
                'loyalty_program_id' => $program->id,
                'loyalty_tier_id' => $startingTier?->id,
                'current_points' => 0,
                'lifetime_points' => 0,
                'points_earned' => 0,
                'points_redeemed' => 0,
                'points_expired' => 0,
                'joined_at' => now(),
            ]);

            \Illuminate\Support\Facades\Log::info('Auto-enrolled customer in loyalty program', [
                'customer_id' => $customer->id,
                'program_id' => $program->id,
            ]);
        } catch (\Exception $e) {
            // Don't fail registration if loyalty enrollment fails
            \Illuminate\Support\Facades\Log::warning('Failed to auto-enroll customer in loyalty program', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Login customer
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // Find customer by email
        $customer = Customer::where('email', $validated['email'])->first();

        if (! $customer || ! Hash::check($validated['password'], $customer->password)) {
            return response()->json([
                'error' => 'Invalid credentials',
            ], 401);
        }

        if (! $customer->is_active) {
            return response()->json([
                'error' => 'Account is inactive',
            ], 403);
        }

        // Auto-enroll in loyalty if not already enrolled (for existing customers)
        $this->autoEnrollInLoyalty($customer);

        // Create session
        $session = $this->createSession($customer);

        return response()->json([
            'success' => true,
            'token' => $session->token,
            'customer' => $this->formatCustomerResponse($customer),
        ]);
    }

    /**
     * Logout customer
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->bearerToken();

        if ($token) {
            WebCustomerSession::where('token', $token)->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Get current customer profile
     */
    public function profile(Request $request): JsonResponse
    {
        $customer = $request->attributes->get('web_customer');

        if (! $customer) {
            return response()->json([
                'error' => 'Unauthorized',
            ], 401);
        }

        return response()->json([
            'customer' => $this->formatCustomerResponse($customer),
        ]);
    }

    /**
     * Update customer profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $customer = $request->attributes->get('web_customer');

        if (! $customer) {
            return response()->json([
                'error' => 'Unauthorized',
            ], 401);
        }

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'phone' => 'nullable|string|max:50',
        ]);

        $customer->update([
            'name' => $validated['first_name'].' '.$validated['last_name'],
            'phone' => $validated['phone'] ?? $customer->phone,
        ]);

        return response()->json([
            'success' => true,
            'customer' => $this->formatCustomerResponse($customer),
        ]);
    }

    /**
     * Create a new session for customer
     */
    private function createSession(Customer $customer): WebCustomerSession
    {
        // Delete any existing sessions for this customer
        WebCustomerSession::where('customer_id', $customer->id)->delete();

        return WebCustomerSession::create([
            'customer_id' => $customer->id,
            'token' => Str::random(64),
            'last_active' => now(),
            'expires_at' => now()->addDays(30),
        ]);
    }

    /**
     * Format customer for API response
     */
    private function formatCustomerResponse(Customer $customer): array
    {
        $nameParts = explode(' ', $customer->name, 2);

        return [
            'id' => $customer->id,
            'email' => $customer->email,
            'name' => $customer->name,
            'first_name' => $nameParts[0] ?? '',
            'last_name' => $nameParts[1] ?? '',
            'phone' => $customer->phone,
            'loyalty_points' => $customer->loyalty_points,
            'created_at' => $customer->created_at?->toIso8601String(),
        ];
    }
}
