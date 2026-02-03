<?php

namespace Modules\PaymentGateway\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\PaymentGateway\Services\PosPaymentService;

class PosPaymentController extends Controller
{
    private PosPaymentService $posPaymentService;

    public function __construct(PosPaymentService $posPaymentService)
    {
        $this->posPaymentService = $posPaymentService;
    }

    /**
     * Get all available payment methods for POS
     */
    public function getPaymentMethods()
    {
        try {
            $methods = $this->posPaymentService->getAvailablePaymentMethods();
            
            return response()->json([
                'success' => true,
                'methods' => $methods,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load payment methods',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Process a payment from POS
     */
    public function processPayment(Request $request)
    {
        try {
            $validated = $request->validate([
                'payment_type' => 'required|in:cash,online,offline',
                'amount' => 'required|numeric|min:0.01',
                'currency' => 'nullable|string|size:3',
                'pos_order_id' => 'required|string',
                'customer_id' => 'nullable|integer',
                'gateway_id' => 'nullable|integer|required_if:payment_type,online',
                'offline_method_id' => 'nullable|integer|required_if:payment_type,offline',
                'reference_number' => 'nullable|string|required_if:payment_type,offline',
                'description' => 'nullable|string',
            ]);

            $result = $this->posPaymentService->processPayment($validated);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Payment processing failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
