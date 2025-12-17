<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with(['incomeAccount', 'expenseAccount', 'category', 'tax'])
            ->orderBy('name');

        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('secondary_name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->input('category_id'));
        }

        $perPage = (int) $request->input('per_page', 20);
        $perPage = max(1, min(200, $perPage));

        $products = $query->paginate($perPage);

        return response()->json($products);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'secondary_name' => 'nullable|string|max:255',
            'sku' => 'required|string|unique:products,sku',
            'barcode' => 'nullable|string|unique:products,barcode',
            'description' => 'nullable|string',
            'category_id' => 'nullable|exists:product_categories,id',
            'type' => 'required|in:product,service',
            'cost' => 'required|numeric|min:0',
            'price' => 'required|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'tax_mode' => 'nullable|in:default,none,custom,tax',
            'tax_included' => 'nullable|boolean',
            'tax_rate_override' => 'nullable|numeric|min:0|max:100',
            'tax_id' => 'nullable|exists:taxes,id',
            'income_account_id' => 'nullable|exists:chart_of_accounts,id',
            'expense_account_id' => 'nullable|exists:chart_of_accounts,id',
            'track_inventory' => 'boolean',
            'stock_quantity' => 'nullable|integer|min:0',
            'reorder_level' => 'nullable|integer|min:0',
            'unit' => 'nullable|string',
            'image' => 'nullable|string',
            'gallery' => 'nullable|array',
            'gallery.*' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $product = Product::create($validated);

        return response()->json($product->load(['incomeAccount', 'expenseAccount', 'category', 'tax']), 201);
    }

    public function show(Product $product)
    {
        return response()->json($product->load(['incomeAccount', 'expenseAccount', 'category', 'tax', 'variants', 'stockMovements']));
    }

    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'name' => 'string|max:255',
            'secondary_name' => 'nullable|string|max:255',
            'sku' => 'string|unique:products,sku,' . $product->id,
            'barcode' => 'nullable|string|unique:products,barcode,' . $product->id,
            'description' => 'nullable|string',
            'category_id' => 'nullable|exists:product_categories,id',
            'type' => 'in:product,service',
            'cost' => 'numeric|min:0',
            'price' => 'numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'tax_mode' => 'nullable|in:default,none,custom,tax',
            'tax_included' => 'nullable|boolean',
            'tax_rate_override' => 'nullable|numeric|min:0|max:100',
            'tax_id' => 'nullable|exists:taxes,id',
            'income_account_id' => 'nullable|exists:chart_of_accounts,id',
            'expense_account_id' => 'nullable|exists:chart_of_accounts,id',
            'track_inventory' => 'boolean',
            'reorder_level' => 'nullable|integer|min:0',
            'unit' => 'nullable|string',
            'image' => 'nullable|string',
            'gallery' => 'nullable|array',
            'gallery.*' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $product->update($validated);

        return response()->json($product->fresh(['incomeAccount', 'expenseAccount', 'category', 'tax']));
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return response()->json(['message' => 'Product deleted successfully']);
    }
}
