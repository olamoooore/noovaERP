<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Modules\Inventory\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

/**
 * Supplier Management Controller
 * Handles vendor/supplier CRUD operations and product associations
 */
class SupplierController extends Controller
{
    /**
     * Display a listing of suppliers
     */
    public function index(Request $request): JsonResponse
    {
        $query = Supplier::query();

        // Search
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('contact_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        // Filter by country
        if ($request->has('country')) {
            $query->where('country', $request->country);
        }

        // Filter by rating
        if ($request->has('min_rating')) {
            $query->where('quality_rating', '>=', $request->min_rating);
        }

        // Sort
        $sortField = $request->get('sort_by', 'name');
        $sortDirection = $request->get('sort_direction', 'asc');
        $query->orderBy($sortField, $sortDirection);

        // With relationships
        if ($request->has('with_products')) {
            $query->withCount('products');
        }

        // Pagination
        $perPage = $request->get('per_page', 15);
        $suppliers = $query->paginate($perPage);

        return response()->json($suppliers);
    }

    /**
     * Store a newly created supplier
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50|unique:suppliers,code',
            'contact_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'website' => 'nullable|url|max:255',
            'address_line_1' => 'nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:2',
            'tax_id' => 'nullable|string|max:50',
            'payment_terms_days' => 'nullable|integer|min:0|max:365',
            'currency' => 'nullable|string|max:3',
            'default_lead_time_days' => 'nullable|integer|min:0|max:365',
            'minimum_order_value' => 'nullable|numeric|min:0',
            'quality_rating' => 'nullable|numeric|min:0|max:5',
            'delivery_rating' => 'nullable|numeric|min:0|max:5',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        // Generate code if not provided
        if (empty($validated['code'])) {
            $validated['code'] = Supplier::generateCode($validated['name']);
        }

        $supplier = Supplier::create($validated);

        return response()->json([
            'message' => 'Supplier created successfully',
            'supplier' => $supplier
        ], 201);
    }

    /**
     * Display the specified supplier
     */
    public function show($supplier): JsonResponse
    {
        $supplier = Supplier::findOrFail($supplier);
        $supplier->load(['products']);
        $supplier->loadCount('products');

        return response()->json($supplier);
    }

    /**
     * Update the specified supplier
     */
    public function update(Request $request, $supplier): JsonResponse
    {
        $supplier = Supplier::findOrFail($supplier);
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('suppliers', 'code')->ignore($supplier->id)],
            'contact_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'website' => 'nullable|url|max:255',
            'address_line_1' => 'nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:2',
            'tax_id' => 'nullable|string|max:50',
            'payment_terms_days' => 'nullable|integer|min:0|max:365',
            'currency' => 'nullable|string|max:3',
            'default_lead_time_days' => 'nullable|integer|min:0|max:365',
            'minimum_order_value' => 'nullable|numeric|min:0',
            'quality_rating' => 'nullable|numeric|min:0|max:5',
            'delivery_rating' => 'nullable|numeric|min:0|max:5',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $supplier->update($validated);

        return response()->json([
            'message' => 'Supplier updated successfully',
            'supplier' => $supplier
        ]);
    }

    /**
     * Remove the specified supplier
     */
    public function destroy($supplier): JsonResponse
    {
        $supplier = Supplier::findOrFail($supplier);
        // Check if supplier has linked products
        if ($supplier->products()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete supplier with linked products. Remove product associations first.'
            ], 422);
        }

        $supplier->delete();

        return response()->json([
            'message' => 'Supplier deleted successfully'
        ]);
    }

    /**
     * Get products linked to this supplier
     */
    public function products($supplier): JsonResponse
    {
        $supplier = Supplier::findOrFail($supplier);
        $products = $supplier->products()
            ->with(['category', 'brand'])
            ->withPivot([
                'supplier_sku', 'cost', 'lead_time_days', 
                'moq', 'is_preferred', 'last_purchase_date'
            ])
            ->paginate(20);

        return response()->json($products);
    }

    /**
     * Link a product to this supplier
     */
    public function linkProduct(Request $request, $supplier): JsonResponse
    {
        $supplier = Supplier::findOrFail($supplier);
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'supplier_sku' => 'nullable|string|max:100',
            'supplier_barcode' => 'nullable|string|max:100',
            'cost' => 'required|numeric|min:0',
            'lead_time_days' => 'nullable|integer|min:0',
            'moq' => 'nullable|integer|min:1',
            'order_multiple' => 'nullable|integer|min:1',
            'priority' => 'nullable|integer|min:1|max:10',
            'is_preferred' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        // Check if already linked
        if ($supplier->products()->where('product_id', $validated['product_id'])->exists()) {
            return response()->json([
                'message' => 'Product is already linked to this supplier'
            ], 422);
        }

        // If this is marked as preferred, unset other preferred suppliers for this product
        if (!empty($validated['is_preferred'])) {
            \App\Models\ProductSupplier::where('product_id', $validated['product_id'])
                ->update(['is_preferred' => false]);
        }

        $supplier->products()->attach($validated['product_id'], [
            'supplier_sku' => $validated['supplier_sku'] ?? null,
            'supplier_barcode' => $validated['supplier_barcode'] ?? null,
            'cost' => $validated['cost'],
            'lead_time_days' => $validated['lead_time_days'] ?? $supplier->default_lead_time_days,
            'moq' => $validated['moq'] ?? 1,
            'order_multiple' => $validated['order_multiple'] ?? 1,
            'priority' => $validated['priority'] ?? 5,
            'is_preferred' => $validated['is_preferred'] ?? false,
            'notes' => $validated['notes'] ?? null,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Product linked to supplier successfully'
        ], 201);
    }

    /**
     * Update product-supplier link
     */
    public function updateProductLink(Request $request, $supplier, int $productId): JsonResponse
    {
        $supplier = Supplier::findOrFail($supplier);
        $validated = $request->validate([
            'supplier_sku' => 'nullable|string|max:100',
            'supplier_barcode' => 'nullable|string|max:100',
            'cost' => 'sometimes|numeric|min:0',
            'lead_time_days' => 'nullable|integer|min:0',
            'moq' => 'nullable|integer|min:1',
            'order_multiple' => 'nullable|integer|min:1',
            'priority' => 'nullable|integer|min:1|max:10',
            'is_preferred' => 'boolean',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        // Check if link exists
        if (!$supplier->products()->where('product_id', $productId)->exists()) {
            return response()->json([
                'message' => 'Product is not linked to this supplier'
            ], 404);
        }

        // If this is marked as preferred, unset other preferred suppliers
        if (!empty($validated['is_preferred'])) {
            \App\Models\ProductSupplier::where('product_id', $productId)
                ->where('supplier_id', '!=', $supplier->id)
                ->update(['is_preferred' => false]);
        }

        $supplier->products()->updateExistingPivot($productId, $validated);

        return response()->json([
            'message' => 'Product-supplier link updated successfully'
        ]);
    }

    /**
     * Unlink a product from this supplier
     */
    public function unlinkProduct($supplier, int $productId): JsonResponse
    {
        $supplier = Supplier::findOrFail($supplier);
        $supplier->products()->detach($productId);

        return response()->json([
            'message' => 'Product unlinked from supplier successfully'
        ]);
    }

    /**
     * Get supplier statistics
     */
    public function statistics(): JsonResponse
    {
        $stats = [
            'total' => Supplier::count(),
            'active' => Supplier::where('is_active', true)->count(),
            'inactive' => Supplier::where('is_active', false)->count(),
            'average_quality_rating' => round(Supplier::whereNotNull('quality_rating')->avg('quality_rating'), 2),
            'average_delivery_rating' => round(Supplier::whereNotNull('delivery_rating')->avg('delivery_rating'), 2),
            'by_country' => Supplier::whereNotNull('country')
                ->selectRaw('country, COUNT(*) as count')
                ->groupBy('country')
                ->orderByDesc('count')
                ->limit(10)
                ->get(),
        ];

        return response()->json($stats);
    }
}
