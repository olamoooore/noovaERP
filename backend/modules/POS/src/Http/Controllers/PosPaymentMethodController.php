<?php

namespace Modules\POS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\POS\Models\PosPaymentMethod;

class PosPaymentMethodController extends Controller
{
    /**
     * List active payment methods
     */
    public function index(): JsonResponse
    {
        $methods = PosPaymentMethod::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json(['payment_methods' => $methods]);
    }

    /**
     * Store new payment method
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:50|unique:pos_payment_methods,code',
            'type' => 'required|in:cash,card,mobile,voucher,credit,other',
            'icon' => 'nullable|string',
            'opens_cash_drawer' => 'boolean',
            'requires_approval' => 'boolean',
            'surcharge_percent' => 'nullable|numeric|min:0|max:100',
            'surcharge_fixed' => 'nullable|numeric|min:0',
        ]);

        $method = PosPaymentMethod::create($request->all());

        return response()->json([
            'message' => 'Payment method created',
            'payment_method' => $method,
        ], 201);
    }

    /**
     * Update payment method
     */
    public function update(Request $request, PosPaymentMethod $paymentMethod): JsonResponse
    {
        $request->validate([
            'name' => 'string|max:100',
            'code' => 'string|max:50|unique:pos_payment_methods,code,' . $paymentMethod->id,
            'type' => 'in:cash,card,mobile,voucher,credit,other',
            'icon' => 'nullable|string',
            'opens_cash_drawer' => 'boolean',
            'requires_approval' => 'boolean',
            'surcharge_percent' => 'nullable|numeric|min:0|max:100',
            'surcharge_fixed' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        $paymentMethod->update($request->all());

        return response()->json([
            'message' => 'Payment method updated',
            'payment_method' => $paymentMethod,
        ]);
    }

    /**
     * Delete payment method
     */
    public function destroy(PosPaymentMethod $paymentMethod): JsonResponse
    {
        // Soft delete by deactivating
        $paymentMethod->update(['is_active' => false]);

        return response()->json(['message' => 'Payment method deactivated']);
    }
}
