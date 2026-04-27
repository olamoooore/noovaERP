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
            'code' => 'required|string|max:255',
        ]);

        $results = Product::findAllSellableByBarcodeOrSku($validated['code']);
        
        if (empty($results)) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        // If single match, return it directly for backward compatibility
        if (count($results) === 1) {
            return response()->json($results[0]);
        }

        // Multiple matches - return array for frontend selection
        return response()->json([
            'multiple_matches' => true,
            'count' => count($results),
            'results' => $results,
        ]);
    }
}
