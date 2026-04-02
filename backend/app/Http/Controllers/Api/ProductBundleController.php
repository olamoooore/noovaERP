<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductBundle;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Product Bundle Controller
 * Manages bundle/kit products and their components
 */
class ProductBundleController extends Controller
{
    /**
     * List all bundles
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::where('is_bundle', true)
            ->with(['bundleComponents.componentProduct', 'category']);

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        // Filter by bundle type
        if ($request->has('bundle_type')) {
            $query->where('bundle_type', $request->bundle_type);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Pagination
        $perPage = $request->get('per_page', 15);
        $bundles = $query->paginate($perPage);

        return response()->json($bundles);
    }

    /**
     * Get bundle components
     */
    public function components($product): JsonResponse
    {
        $product = Product::findOrFail($product);
        if (!$product->is_bundle) {
            return response()->json([
                'message' => 'This product is not a bundle'
            ], 422);
        }

        $components = $product->bundleComponents()
            ->with(['componentProduct' => function ($q) {
                $q->select('id', 'name', 'sku', 'price', 'cost', 'stock_quantity', 'image');
            }])
            ->get();

        $totalCost = $components->sum(function ($c) {
            return $c->componentProduct->cost * $c->quantity;
        });

        $totalPrice = $components->sum(function ($c) {
            return $c->discounted_price;
        });

        return response()->json([
            'components' => $components,
            'summary' => [
                'total_components' => $components->count(),
                'required_components' => $components->where('is_optional', false)->count(),
                'optional_components' => $components->where('is_optional', true)->count(),
                'total_cost' => round($totalCost, 2),
                'total_regular_price' => round($totalPrice, 2),
                'bundle_price' => $product->price,
                'savings' => round($totalPrice - $product->price, 2),
                'savings_percentage' => $totalPrice > 0 ? round((($totalPrice - $product->price) / $totalPrice) * 100, 2) : 0,
            ]
        ]);
    }

    /**
     * Add component to bundle
     */
    public function addComponent(Request $request, $bundle): JsonResponse
    {
        $bundle = Product::findOrFail($bundle);
        if (!$bundle->is_bundle) {
            return response()->json([
                'message' => 'This product is not a bundle'
            ], 422);
        }

        $validated = $request->validate([
            'component_product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'discount_type' => 'nullable|in:percentage,fixed',
            'discount_value' => 'nullable|numeric|min:0',
            'is_optional' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        // Check for circular reference
        if ($validated['component_product_id'] == $bundle->id) {
            return response()->json([
                'message' => 'A bundle cannot contain itself'
            ], 422);
        }

        // Check if component is already in bundle
        if ($bundle->bundleComponents()->where('component_product_id', $validated['component_product_id'])->exists()) {
            return response()->json([
                'message' => 'This product is already a component of this bundle'
            ], 422);
        }

        // Check if component is also a bundle (prevent nested bundles for simplicity)
        $component = Product::find($validated['component_product_id']);
        if ($component->is_bundle) {
            return response()->json([
                'message' => 'Cannot add another bundle as a component. Nested bundles are not supported.'
            ], 422);
        }

        $bundleComponent = ProductBundle::create([
            'bundle_product_id' => $bundle->id,
            'component_product_id' => $validated['component_product_id'],
            'quantity' => $validated['quantity'],
            'discount_type' => $validated['discount_type'] ?? null,
            'discount_value' => $validated['discount_value'] ?? 0,
            'is_optional' => $validated['is_optional'] ?? false,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        $bundleComponent->load('componentProduct');

        return response()->json([
            'message' => 'Component added to bundle successfully',
            'component' => $bundleComponent
        ], 201);
    }

    /**
     * Update bundle component
     */
    public function updateComponent(Request $request, $bundle, $component): JsonResponse
    {
        $bundle = Product::findOrFail($bundle);
        $component = ProductBundle::findOrFail($component);
        if ($component->bundle_product_id !== $bundle->id) {
            return response()->json([
                'message' => 'Component does not belong to this bundle'
            ], 404);
        }

        $validated = $request->validate([
            'quantity' => 'sometimes|integer|min:1',
            'discount_type' => 'nullable|in:percentage,fixed',
            'discount_value' => 'nullable|numeric|min:0',
            'is_optional' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $component->update($validated);
        $component->load('componentProduct');

        return response()->json([
            'message' => 'Component updated successfully',
            'component' => $component
        ]);
    }

    /**
     * Remove component from bundle
     */
    public function removeComponent($bundle, $component): JsonResponse
    {
        $bundle = Product::findOrFail($bundle);
        $component = ProductBundle::findOrFail($component);
        if ($component->bundle_product_id !== $bundle->id) {
            return response()->json([
                'message' => 'Component does not belong to this bundle'
            ], 404);
        }

        $component->delete();

        return response()->json([
            'message' => 'Component removed from bundle successfully'
        ]);
    }

    /**
     * Reorder bundle components
     */
    public function reorderComponents(Request $request, $bundle): JsonResponse
    {
        $bundle = Product::findOrFail($bundle);
        if (!$bundle->is_bundle) {
            return response()->json([
                'message' => 'This product is not a bundle'
            ], 422);
        }

        $validated = $request->validate([
            'components' => 'required|array',
            'components.*.id' => 'required|integer|exists:product_bundles,id',
            'components.*.sort_order' => 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($validated, $bundle) {
            foreach ($validated['components'] as $item) {
                ProductBundle::where('id', $item['id'])
                    ->where('bundle_product_id', $bundle->id)
                    ->update(['sort_order' => $item['sort_order']]);
            }
        });

        return response()->json([
            'message' => 'Components reordered successfully'
        ]);
    }

    /**
     * Check if bundle can be fulfilled
     */
    public function checkAvailability($bundle, Request $request): JsonResponse
    {
        $bundle = Product::findOrFail($bundle);
        if (!$bundle->is_bundle) {
            return response()->json([
                'message' => 'This product is not a bundle'
            ], 422);
        }

        $quantity = $request->get('quantity', 1);
        $warehouseId = $request->get('warehouse_id');

        $components = $bundle->bundleComponents()
            ->with('componentProduct')
            ->get();

        $availability = [];
        $canFulfill = true;

        foreach ($components as $component) {
            $product = $component->componentProduct;
            $needed = $component->quantity * $quantity;
            
            $available = $warehouseId 
                ? $product->getStockInWarehouse($warehouseId)
                : $product->stock_quantity;

            $componentCanFulfill = $component->is_optional || $available >= $needed;
            
            if (!$componentCanFulfill) {
                $canFulfill = false;
            }

            $availability[] = [
                'component_id' => $component->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_sku' => $product->sku,
                'quantity_needed' => $needed,
                'quantity_available' => $available,
                'is_optional' => $component->is_optional,
                'can_fulfill' => $componentCanFulfill,
                'shortage' => max(0, $needed - $available),
            ];
        }

        return response()->json([
            'bundle_id' => $bundle->id,
            'bundle_name' => $bundle->name,
            'requested_quantity' => $quantity,
            'can_fulfill' => $canFulfill,
            'max_fulfillable' => $this->calculateMaxFulfillable($components),
            'components' => $availability,
        ]);
    }

    /**
     * Calculate maximum bundles that can be fulfilled
     */
    private function calculateMaxFulfillable($components): int
    {
        $maxQuantity = PHP_INT_MAX;

        foreach ($components as $component) {
            if ($component->is_optional) {
                continue;
            }

            $product = $component->componentProduct;
            if ($component->quantity > 0) {
                $possible = floor($product->stock_quantity / $component->quantity);
                $maxQuantity = min($maxQuantity, $possible);
            }
        }

        return $maxQuantity === PHP_INT_MAX ? 0 : (int) $maxQuantity;
    }

    /**
     * Convert a product to a bundle
     */
    public function convertToBundle(Request $request, $product): JsonResponse
    {
        $product = Product::findOrFail($product);
        if ($product->is_bundle) {
            return response()->json([
                'message' => 'This product is already a bundle'
            ], 422);
        }

        $validated = $request->validate([
            'bundle_type' => 'required|in:fixed,dynamic,customizable',
        ]);

        $product->update([
            'is_bundle' => true,
            'bundle_type' => $validated['bundle_type'],
        ]);

        return response()->json([
            'message' => 'Product converted to bundle successfully',
            'product' => $product
        ]);
    }

    /**
     * Remove bundle status from product
     */
    public function removeBundle($product): JsonResponse
    {
        $product = Product::findOrFail($product);
        if (!$product->is_bundle) {
            return response()->json([
                'message' => 'This product is not a bundle'
            ], 422);
        }

        // Remove all components
        $product->bundleComponents()->delete();

        $product->update([
            'is_bundle' => false,
            'bundle_type' => null,
        ]);

        return response()->json([
            'message' => 'Bundle removed successfully. Product is now a regular product.'
        ]);
    }
}
