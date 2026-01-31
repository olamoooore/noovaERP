<?php

namespace Modules\POS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\POS\Models\PosHeldOrder;
use Modules\POS\Models\PosSession;

class PosHeldOrderController extends Controller
{
    /**
     * List held orders for current session
     */
    public function index(Request $request): JsonResponse
    {
        $session = PosSession::where('user_id', Auth::id())
            ->where('status', 'open')
            ->first();

        if (!$session) {
            return response()->json([
                'error' => 'No active session',
                'held_orders' => [],
            ], 400);
        }

        $heldOrders = PosHeldOrder::where('session_id', $session->id)
            ->with('customer')
            ->orderBy('held_at', 'desc')
            ->get();

        return response()->json(['held_orders' => $heldOrders]);
    }

    /**
     * Hold/park current cart
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'cart_data' => 'required|array',
            'cart_data.items' => 'required|array|min:1',
            'customer_id' => 'nullable|exists:customers,id',
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ]);

        $session = PosSession::where('user_id', Auth::id())
            ->where('status', 'open')
            ->first();

        if (!$session) {
            return response()->json(['error' => 'No active session'], 400);
        }

        $cartData = $request->cart_data;
        
        // Calculate totals from cart
        $subtotal = collect($cartData['items'])->sum(function ($item) {
            return ($item['unit_price'] ?? $item['price'] ?? 0) * ($item['quantity'] ?? 1);
        });
        $taxAmount = $cartData['tax_amount'] ?? 0;
        $total = $subtotal + $taxAmount;

        $heldOrder = PosHeldOrder::create([
            'session_id' => $session->id,
            'customer_id' => $request->customer_id,
            'reference' => $request->reference,
            'cart_data' => $cartData,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'notes' => $request->notes,
        ]);

        return response()->json([
            'message' => 'Order held successfully',
            'held_order' => $heldOrder->load('customer'),
        ]);
    }

    /**
     * Recall held order
     */
    public function show(PosHeldOrder $heldOrder): JsonResponse
    {
        return response()->json([
            'held_order' => $heldOrder->load('customer'),
        ]);
    }

    /**
     * Delete held order (after recalling or canceling)
     */
    public function destroy(PosHeldOrder $heldOrder): JsonResponse
    {
        $session = PosSession::where('user_id', Auth::id())
            ->where('status', 'open')
            ->first();

        if (!$session || $heldOrder->session_id !== $session->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $heldOrder->delete();

        return response()->json(['message' => 'Held order removed']);
    }
}
