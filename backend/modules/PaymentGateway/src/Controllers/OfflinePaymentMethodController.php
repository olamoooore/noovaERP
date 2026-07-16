<?php

namespace Modules\PaymentGateway\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
use Modules\PaymentGateway\Models\OfflinePaymentMethod;

class OfflinePaymentMethodController extends Controller
{
    /**
     * List all offline payment methods
     */
    public function index()
    {
        $methods = OfflinePaymentMethod::with('paymentGateway')
            ->ordered()
            ->get();

        return response()->json($methods);
    }

    /**
     * Store a new offline payment method
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'method_name' => 'required|string|max:255',
            'instructions' => 'nullable|string',
            'account_details' => 'nullable|array',
            'is_active' => 'boolean',
            'display_order' => 'integer|min:0',
        ]);

        // Generate method code from name
        $validated['method_code'] = 'offline_'.Str::slug($validated['method_name'], '_');

        // Create or find the offline payment gateway
        $gateway = \Modules\PaymentGateway\Models\PaymentGateway::firstOrCreate(
            ['gateway_name' => 'offline', 'gateway_type' => 'offline'],
            [
                'is_active' => true,
                'is_test_mode' => false,
                'created_by' => TenantUserHelper::resolveTenantUserId(),
            ]
        );

        $validated['payment_gateway_id'] = $gateway->id;

        $method = OfflinePaymentMethod::create($validated);

        return response()->json([
            'message' => 'Payment method created successfully',
            'method' => $method,
        ], 201);
    }

    /**
     * Update an offline payment method
     */
    public function update(Request $request, $offlinePaymentMethod)
    {
        $offlinePaymentMethod = OfflinePaymentMethod::findOrFail($offlinePaymentMethod);

        $validated = $request->validate([
            'method_name' => 'sometimes|string|max:255',
            'instructions' => 'nullable|string',
            'account_details' => 'nullable|array',
            'is_active' => 'boolean',
            'display_order' => 'integer|min:0',
        ]);

        if (isset($validated['method_name'])) {
            $validated['method_code'] = 'offline_'.Str::slug($validated['method_name'], '_');
        }

        $offlinePaymentMethod->update($validated);

        return response()->json([
            'message' => 'Payment method updated successfully',
            'method' => $offlinePaymentMethod->fresh(),
        ]);
    }

    /**
     * Delete an offline payment method
     */
    public function destroy($offlinePaymentMethod)
    {
        $offlinePaymentMethod = OfflinePaymentMethod::findOrFail($offlinePaymentMethod);

        $offlinePaymentMethod->delete();

        return response()->json([
            'message' => 'Payment method deleted successfully',
        ]);
    }
}
