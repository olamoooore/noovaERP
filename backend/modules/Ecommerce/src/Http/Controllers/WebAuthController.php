<?php

namespace Modules\Ecommerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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
            'email' => 'required|email|max:255|unique:customers,email',
            'password' => 'required|string|min:8|confirmed',
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'phone' => 'nullable|string|max:50',
            'guest_token' => 'nullable|string',
        ]);

        // Create customer
        $customer = Customer::create([
            'name' => $validated['first_name'] . ' ' . $validated['last_name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'type' => 'individual',
            'is_active' => true,
            'password' => Hash::make($validated['password']),
        ]);

        // Link guest orders by token
        if (!empty($validated['guest_token'])) {
            WebOrder::where('guest_token', $validated['guest_token'])
                ->whereNull('customer_id')
                ->update(['customer_id' => $customer->id]);
        }

        // Also link any orders with matching email
        WebOrder::where('email', $validated['email'])
            ->whereNull('customer_id')
            ->update(['customer_id' => $customer->id]);

        // Create session
        $session = $this->createSession($customer);

        return response()->json([
            'success' => true,
            'token' => $session->token,
            'customer' => $this->formatCustomerResponse($customer),
        ], 201);
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

        if (!$customer || !Hash::check($validated['password'], $customer->password)) {
            return response()->json([
                'error' => 'Invalid credentials',
            ], 401);
        }

        if (!$customer->is_active) {
            return response()->json([
                'error' => 'Account is inactive',
            ], 403);
        }

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

        if (!$customer) {
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

        if (!$customer) {
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
            'name' => $validated['first_name'] . ' ' . $validated['last_name'],
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
            'first_name' => $nameParts[0] ?? '',
            'last_name' => $nameParts[1] ?? '',
            'phone' => $customer->phone,
            'loyalty_points' => $customer->loyalty_points,
        ];
    }
}