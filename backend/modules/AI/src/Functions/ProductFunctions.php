<?php

namespace Modules\AI\Functions;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Support\Facades\Log;

/**
 * Product Functions
 * 
 * AI-callable functions for product queries
 */
class ProductFunctions
{
    /**
     * Search products by name, SKU, or description
     * 
     * @param array $args Arguments with query and optional category
     * @return array Search results
     */
    public static function search(array $args): array
    {
        try {
            $query = $args['query'] ?? '';
            $categoryId = $args['category_id'] ?? null;
            $limit = $args['limit'] ?? 10;
            
            // Build query using Eloquent
            $productsQuery = Product::query()
                ->with(['category:id,name'])
                ->where('is_active', true)
                ->where(function ($q) use ($query) {
                    $q->where('name', 'ilike', "%{$query}%")
                      ->orWhere('description', 'ilike', "%{$query}%")
                      ->orWhere('sku', 'ilike', "%{$query}%")
                      ->orWhere('barcode', 'ilike', "%{$query}%");
                });
            
            // Filter by category if provided
            if ($categoryId) {
                $productsQuery->where('category_id', $categoryId);
            }
            
            $products = $productsQuery
                ->select('id', 'name', 'sku', 'barcode', 'description', 'price', 'stock_quantity', 'category_id', 'track_inventory', 'unit')
                ->take($limit)
                ->get()
                ->map(function ($product) {
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'sku' => $product->sku,
                        'barcode' => $product->barcode,
                        'description' => $product->description,
                        'price' => (float) $product->price,
                        'stock' => $product->track_inventory ? $product->stock_quantity : null,
                        'category' => $product->category ? $product->category->name : null,
                        'unit' => $product->unit,
                        'in_stock' => !$product->track_inventory || $product->stock_quantity > 0,
                    ];
                })
                ->toArray();
            
            return [
                'success' => true,
                'found' => count($products),
                'query' => $query,
                'products' => $products,
            ];
            
        } catch (\Exception $e) {
            Log::error('ProductFunctions::search failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);
            
            return [
                'success' => false,
                'error' => 'Unable to search products at this time',
                'message' => 'Product search is currently unavailable. Please try again later.',
            ];
        }
    }
    
    /**
     * Get detailed product information by ID or SKU
     * 
     * @param array $args Arguments with product_id or sku
     * @return array Product details
     */
    public static function getDetails(array $args): array
    {
        try {
            $productId = $args['product_id'] ?? null;
            $sku = $args['sku'] ?? null;
            
            if (!$productId && !$sku) {
                return [
                    'success' => false,
                    'error' => 'Please provide either product_id or sku',
                ];
            }
            
            $query = Product::query()->with(['category:id,name']);
            
            if ($productId) {
                $product = $query->find($productId);
            } else {
                $product = $query->where('sku', $sku)->first();
            }
            
            if (!$product) {
                return [
                    'success' => false,
                    'error' => 'Product not found',
                    'message' => 'The requested product could not be found.',
                ];
            }
            
            // Load variants count
            $variantsCount = $product->variants()->count();
            
            return [
                'success' => true,
                'product' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'secondary_name' => $product->secondary_name,
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                    'description' => $product->description,
                    'category' => $product->category ? $product->category->name : null,
                    'price' => (float) $product->price,
                    'cost' => (float) $product->cost,
                    'stock_quantity' => $product->track_inventory ? $product->stock_quantity : null,
                    'track_inventory' => $product->track_inventory,
                    'reorder_level' => $product->reorder_level,
                    'unit' => $product->unit,
                    'is_active' => $product->is_active,
                    'in_stock' => !$product->track_inventory || $product->stock_quantity > 0,
                    'needs_reorder' => $product->track_inventory && $product->stock_quantity <= $product->reorder_level,
                    'has_variants' => $variantsCount > 0,
                    'variants_count' => $variantsCount,
                ],
            ];
            
        } catch (\Exception $e) {
            Log::error('ProductFunctions::getDetails failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);
            
            return [
                'success' => false,
                'error' => 'Unable to retrieve product details',
                'message' => 'Product information is currently unavailable.',
            ];
        }
    }
    
    /**
     * Check product availability
     * 
     * @param array $args Arguments with product_id or sku
     * @return array Availability information
     */
    public static function checkAvailability(array $args): array
    {
        try {
            $productId = $args['product_id'] ?? null;
            $sku = $args['sku'] ?? null;
            $quantity = $args['quantity'] ?? 1;
            
            if (!$productId && !$sku) {
                return [
                    'success' => false,
                    'error' => 'Please provide either product_id or sku',
                ];
            }
            
            $query = Product::query();
            
            if ($productId) {
                $product = $query->find($productId);
            } else {
                $product = $query->where('sku', $sku)->first();
            }
            
            if (!$product) {
                return [
                    'success' => false,
                    'error' => 'Product not found',
                ];
            }
            
            // If product doesn't track inventory, it's always available
            if (!$product->track_inventory) {
                return [
                    'success' => true,
                    'available' => true,
                    'product_name' => $product->name,
                    'message' => 'Product is available (inventory not tracked)',
                ];
            }
            
            $available = $product->stock_quantity >= $quantity;
            
            return [
                'success' => true,
                'available' => $available,
                'product_name' => $product->name,
                'requested_quantity' => $quantity,
                'stock_quantity' => $product->stock_quantity,
                'message' => $available 
                    ? "Available: {$product->stock_quantity} units in stock"
                    : "Out of stock: Only {$product->stock_quantity} units available",
            ];
            
        } catch (\Exception $e) {
            Log::error('ProductFunctions::checkAvailability failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);
            
            return [
                'success' => false,
                'error' => 'Unable to check availability',
            ];
        }
    }
    
    /**
     * List product categories
     * 
     * @param array $args Optional arguments
     * @return array Categories list
     */
    public static function listCategories(array $args = []): array
    {
        try {
            $categories = ProductCategory::query()
                ->where('is_active', true)
                ->select('id', 'name', 'parent_id')
                ->orderBy('name')
                ->get()
                ->map(function ($category) {
                    return [
                        'id' => $category->id,
                        'name' => $category->name,
                        'parent_id' => $category->parent_id,
                    ];
                })
                ->toArray();
            
            return [
                'success' => true,
                'categories' => $categories,
                'total' => count($categories),
            ];
            
        } catch (\Exception $e) {
            Log::error('ProductFunctions::listCategories failed', [
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'error' => 'Unable to retrieve categories',
            ];
        }
    }
}
