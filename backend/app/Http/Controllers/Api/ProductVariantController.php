<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;

class ProductVariantController extends Controller
{
    public function index($product)
    {
        $product = Product::findOrFail($product);
        return response()->json($product->variants()->orderBy('name')->get());
    }

    public function store(Request $request, $product)
    {
        $product = Product::findOrFail($product);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'required|string|unique:product_variants,sku',
            'barcode' => 'nullable|string|unique:product_variants,barcode',
            'cost' => 'nullable|numeric|min:0',
            'price' => 'required|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'track_inventory' => 'boolean',
            'stock_quantity' => 'nullable|integer|min:0',
            'reorder_level' => 'nullable|integer|min:0',
            'unit' => 'nullable|string',
            'attributes' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        unset($validated['stock_quantity']);

        $variant = $product->variants()->create($validated);

        return response()->json($variant, 201);
    }

    public function update(Request $request, $productVariant)
    {
        $productVariant = ProductVariant::findOrFail($productVariant);
        $validated = $request->validate([
            'name' => 'string|max:255',
            'sku' => 'string|unique:product_variants,sku,' . $productVariant->id,
            'barcode' => 'nullable|string|unique:product_variants,barcode,' . $productVariant->id,
            'cost' => 'numeric|min:0',
            'price' => 'numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'track_inventory' => 'boolean',
            'reorder_level' => 'nullable|integer|min:0',
            'unit' => 'nullable|string',
            'attributes' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $productVariant->update($validated);

        return response()->json($productVariant->fresh());
    }

    public function destroy($productVariant)
    {
        $productVariant = ProductVariant::findOrFail($productVariant);
        $productVariant->delete();

        return response()->json(['message' => 'Variant deleted successfully']);
    }
}
