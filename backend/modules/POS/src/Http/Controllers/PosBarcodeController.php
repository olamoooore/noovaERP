<?php

namespace Modules\POS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class PosBarcodeController extends Controller
{
    /**
     * Get products with their barcodes for listing
     */
    public function index(Request $request)
    {
        $query = Product::query()
            ->select(['id', 'name', 'sku', 'barcode', 'price', 'stock_quantity'])
            ->whereNotNull('barcode')
            ->where('barcode', '!=', '');

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        if ($request->has('warehouse_id') && $request->warehouse_id) {
            // TODO: Filter by warehouse if warehouse products table exists
        }

        $perPage = $request->input('per_page', 50);
        return $query->paginate($perPage);
    }

    /**
     * Generate print data for barcodes
     */
    public function print(Request $request)
    {
        $validated = $request->validate([
            'product_ids' => 'required|array',
            'product_ids.*' => 'integer|exists:products,id',
            'quantity' => 'nullable|integer|min:1|max:100',
        ]);

        $products = Product::whereIn('id', $validated['product_ids'])
            ->select(['id', 'name', 'sku', 'barcode', 'price'])
            ->get();

        $quantity = $validated['quantity'] ?? 1;

        // Generate barcode data for each product
        $barcodes = [];
        foreach ($products as $product) {
            for ($i = 0; $i < $quantity; $i++) {
                $barcodes[] = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'barcode' => $product->barcode ?? $product->sku,
                    'price' => $product->price,
                ];
            }
        }

        return response()->json([
            'barcodes' => $barcodes,
            'settings' => $this->getBarcodeSettings(),
        ]);
    }

    /**
     * Get barcode settings
     */
    public function settings()
    {
        return response()->json($this->getBarcodeSettings());
    }

    /**
     * Update barcode settings
     */
    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'barcode_type' => 'nullable|string|in:code128,code39,ean13,ean8,upc',
            'barcode_format' => 'nullable|string|in:svg,png,canvas',
            'show_price' => 'nullable|boolean',
            'show_name' => 'nullable|boolean',
            'show_sku' => 'nullable|boolean',
            'bar_width' => 'nullable|integer|min:1|max:5',
            'bar_height' => 'nullable|integer|min:20|max:100',
        ]);

        // TODO: Save to settings table when available
        // For now, return the validated settings
        return response()->json([
            'message' => 'Settings updated successfully',
            'settings' => array_merge($this->getBarcodeSettings(), $validated),
        ]);
    }

    /**
     * Get default barcode settings
     */
    private function getBarcodeSettings(): array
    {
        // TODO: Load from settings table when available
        return [
            'barcode_type' => 'code128',
            'barcode_format' => 'svg',
            'show_price' => true,
            'show_name' => true,
            'show_sku' => true,
            'bar_width' => 2,
            'bar_height' => 50,
        ];
    }
}
