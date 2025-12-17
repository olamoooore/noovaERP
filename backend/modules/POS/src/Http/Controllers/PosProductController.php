<?php

namespace Modules\POS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class PosProductController extends Controller
{
    public function lookup(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string',
        ]);

        $found = Product::findSellableByBarcodeOrSku($validated['code']);
        if (!$found) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        return response()->json($found);
    }
}
