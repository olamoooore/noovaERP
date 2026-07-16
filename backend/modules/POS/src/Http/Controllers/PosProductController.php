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
            'code' => 'nullable|string|max:255|required_without:barcode',
            'barcode' => 'nullable|string|max:255|required_without:code',
        ]);

        $lookupCode = trim((string) ($validated['code'] ?? $validated['barcode'] ?? ''));
        if ($lookupCode === '') {
            return response()->json(['message' => 'Barcode or code is required'], 422);
        }

        $results = Product::findAllSellableByBarcodeOrSku($lookupCode);

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
