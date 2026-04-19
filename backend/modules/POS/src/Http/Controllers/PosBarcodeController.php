<?php

namespace Modules\POS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Modules\POS\Models\PosSetting;

class PosBarcodeController extends Controller
{
    /**
     * Get products with their barcodes for listing
     */
    public function index(Request $request)
    {
        $query = Product::query()
            ->select(['id', 'name', 'sku', 'barcode', 'price', 'stock_quantity'])
            ;

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
            'all' => 'nullable|boolean',
            'search' => 'nullable|string',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|exists:products,id',
            'quantity' => 'nullable|integer|min:1|max:100',
        ]);

        $quantity = $validated['quantity'] ?? 1;

        $productsQuery = Product::query()->select(['id', 'name', 'sku', 'barcode', 'price']);

        if (!empty($validated['search'])) {
            $search = $validated['search'];
            $productsQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        $all = (bool) ($validated['all'] ?? false);
        if (!$all) {
            $ids = $validated['product_ids'] ?? [];
            if (empty($ids)) {
                return response()->json(['message' => 'No products selected'], 422);
            }
            $productsQuery->whereIn('id', $ids);
        }

        $products = $productsQuery->get();

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

        // Persist to POS settings table with barcode_ prefix
        $map = [
            'barcode_type'   => 'barcode_type',
            'barcode_format' => 'barcode_format',
            'show_price'     => 'barcode_show_price',
            'show_name'      => 'barcode_show_name',
            'show_sku'       => 'barcode_show_sku',
            'bar_width'      => 'barcode_bar_width',
            'bar_height'     => 'barcode_bar_height',
        ];
        foreach ($validated as $k => $v) {
            $settingKey = $map[$k] ?? $k;
            PosSetting::setValue($settingKey, $v);
        }

        return response()->json([
            'message' => 'Settings updated successfully',
            'settings' => $this->getBarcodeSettings(),
        ]);
    }

    /**
     * Get default barcode settings
     */
    private function getBarcodeSettings(): array
    {
        return [
            'barcode_type'   => PosSetting::getValue('barcode_type', 'code128'),
            'barcode_format' => PosSetting::getValue('barcode_format', 'svg'),
            'show_price'     => PosSetting::getValue('barcode_show_price', true),
            'show_name'      => PosSetting::getValue('barcode_show_name', true),
            'show_sku'       => PosSetting::getValue('barcode_show_sku', true),
            'bar_width'      => PosSetting::getValue('barcode_bar_width', 2),
            'bar_height'     => PosSetting::getValue('barcode_bar_height', 50),
        ];
    }
}
