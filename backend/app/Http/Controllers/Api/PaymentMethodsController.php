<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;

/**
 * #486: read-only endpoint exposing the tenant payment-method master.
 * The payment forms build their method dropdowns from this list instead of
 * a hardcoded 5-item array.
 */
class PaymentMethodsController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => PaymentMethod::query()
                ->active()
                ->orderBy('sort_order')
                ->orderBy('code')
                ->get(),
        ]);
    }
}
