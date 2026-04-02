<?php

namespace Modules\PaymentGateway\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Services\PaymentGatewayFactory;

class PaymentGatewayController extends Controller
{
    /**
     * List all payment gateways
     */
    public function index()
    {
        $gateways = PaymentGateway::with(['creator', 'updater'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($gateways);
    }

    /**
     * Get available gateway types
     */
    public function available()
    {
        return response()->json(PaymentGatewayFactory::getAvailableGateways());
    }

    /**
     * Store a new payment gateway
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'gateway_name' => 'required|string|in:stripe,hitpay,paypal',
            'publishable_key' => 'nullable|string',
            'api_key' => 'required|string',
            'api_secret' => 'nullable|string',
            'webhook_secret' => 'nullable|string',
            'is_active' => 'boolean',
            'is_test_mode' => 'boolean',
            'config' => 'nullable|array',
        ]);

        $validated['created_by'] = auth()->id();

        $gateway = PaymentGateway::create($validated);

        return response()->json([
            'message' => 'Payment gateway configured successfully',
            'gateway' => $gateway,
        ], 201);
    }

    /**
     * Show a specific gateway
     */
    public function show($gateway)
    {
        $gateway = PaymentGateway::findOrFail($gateway);
        return response()->json($gateway->load(['creator', 'updater', 'transactions']));
    }

    /**
     * Update a payment gateway
     */
    public function update(Request $request, $gateway)
    {
        $gateway = PaymentGateway::findOrFail($gateway);

        $validated = $request->validate([
            'publishable_key' => 'nullable|string',
            'api_key' => 'sometimes|string',
            'api_secret' => 'nullable|string',
            'webhook_secret' => 'nullable|string',
            'is_active' => 'boolean',
            'is_test_mode' => 'boolean',
            'config' => 'nullable|array',
        ]);

        $validated['updated_by'] = auth()->id();

        $gateway->update($validated);

        return response()->json([
            'message' => 'Payment gateway updated successfully',
            'gateway' => $gateway,
        ]);
    }

    /**
     * Delete a payment gateway
     */
    public function destroy($gateway)
    {
        $gateway = PaymentGateway::findOrFail($gateway);

        // Check if gateway has transactions
        if ($gateway->transactions()->exists()) {
            return response()->json([
                'message' => 'Cannot delete gateway with existing transactions',
            ], 422);
        }

        $gateway->delete();

        return response()->json([
            'message' => 'Payment gateway deleted successfully',
        ]);
    }

    /**
     * Test gateway connection
     */
    public function test($gateway)
    {
        $gateway = PaymentGateway::findOrFail($gateway);

        try {
            $service = PaymentGatewayFactory::create($gateway);
            $success = $service->testConnection();

            if ($success) {
                return response()->json([
                    'message' => 'Connection successful',
                    'status' => 'connected',
                ]);
            }

            return response()->json([
                'message' => 'Connection failed',
                'status' => 'failed',
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Connection test failed: ' . $e->getMessage(),
                'status' => 'error',
            ], 500);
        }
    }

    /**
     * Toggle gateway active status
     * Multiple gateways can be active simultaneously
     */
    public function toggle($gateway)
    {
        $gateway = PaymentGateway::findOrFail($gateway);

        $newStatus = !$gateway->is_active;

        $gateway->update([
            'is_active' => $newStatus,
            'updated_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => $newStatus
                ? 'Gateway activated'
                : 'Gateway deactivated',
            'is_active' => $gateway->is_active,
        ]);
    }
}
