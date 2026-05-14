<?php

namespace Modules\WebsiteBuilder\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Controller for public website product API.
 * Returns tenant products for e-commerce websites.
 */
class WebsiteProductController extends Controller
{
    /**
     * Get all products visible on website.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()
            ->where('is_active', true)
            ->withSum('stockLevels as aggregated_stock_quantity', 'quantity')
            ->with(['category', 'brand', 'bundleComponents.componentProduct'])
            ->orderBy('created_at', 'desc');

        $supportsEventProductLinks = Schema::hasTable('event_product_links');

        $this->applyWebsiteVisibilityFilter($query, $request);

        // Filter by category
        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->input('category_id'));
        }

        // Filter by brand
        if ($request->filled('brand_id')) {
            $query->where('brand_id', (int) $request->input('brand_id'));
        }

        // Filter by new arrivals
        if ($request->filled('is_new_arrival')) {
            $query->where('is_new_arrival', true);
        }

        if ($supportsEventProductLinks && $request->filled('event_id')) {
            $eventId = (int) $request->input('event_id');
            $relationType = (string) $request->input('event_relation_type', '');

            if (in_array($relationType, ['merchandise', 'bundle'], true)) {
                $relation = $relationType === 'bundle' ? 'bundleEvents' : 'merchandiseEvents';
                $query->whereHas($relation, function (Builder $relationQuery) use ($eventId) {
                    $relationQuery->where('events.id', $eventId);
                });
            } else {
                $query->whereHas('linkedEvents', function (Builder $relationQuery) use ($eventId) {
                    $relationQuery->where('events.id', $eventId);
                });
            }
        }

        if ($request->filled('is_bundle')) {
            $isBundle = filter_var($request->input('is_bundle'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isBundle !== null) {
                $query->where('is_bundle', $isBundle);
            }
        }

        // Filter by price range
        if ($request->filled('min_price')) {
            $query->where('price', '>=', (float) $request->input('min_price'));
        }
        if ($request->filled('max_price')) {
            $query->where('price', '<=', (float) $request->input('max_price'));
        }

        // Search
        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $searchLower = strtolower($search);
            $query->where(function ($q) use ($search, $searchLower) {
                $q->whereRaw('LOWER(name) LIKE ?', ['%' . $searchLower . '%'])
                    ->orWhereRaw('LOWER(secondary_name) LIKE ?', ['%' . $searchLower . '%'])
                    ->orWhereRaw('LOWER(description) LIKE ?', ['%' . $searchLower . '%'])
                    ->orWhereRaw('LOWER(meta_keywords) LIKE ?', ['%' . $searchLower . '%'])
                    ->orWhereRaw('LOWER(sku) LIKE ?', ['%' . $searchLower . '%']);
            });
        }

        // Filter by tag (meta_keywords)
        if ($request->filled('tag')) {
            $tag = (string) $request->input('tag');
            $query->where('meta_keywords', 'like', "%{$tag}%");
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = strtolower($request->input('sort_dir', 'desc'));
        $allowedSorts = ['name', 'price', 'created_at'];
        
        if (in_array($sortBy, $allowedSorts)) {
            $query->reorder($sortBy, in_array($sortDir, ['asc', 'desc']) ? $sortDir : 'asc');
        }

        // Pagination
        $perPage = min(max((int) $request->input('per_page', 12), 1), 100);
        $products = $query->paginate($perPage);

        // Transform for public API (hide sensitive fields)
        $products->getCollection()->transform(function ($product) {
            $effectiveStockQuantity = $product->track_inventory
                ? (int) ($product->aggregated_stock_quantity ?? 0)
                : (int) ($product->stock_quantity ?? 0);

            // Convert image path to absolute URL if it's a relative path
            $imageUrl = $this->getAbsoluteImageUrl($product->image);
            
            // Convert gallery images to absolute URLs
            $gallery = $product->gallery ?? [];
            if (is_array($gallery)) {
                $gallery = array_map(fn($img) => $this->getAbsoluteImageUrl($img), $gallery);
            }
            
            return [
                'id' => $product->id,
                'name' => $product->name,
                'secondary_name' => $product->secondary_name,
                'slug' => \Str::slug($product->name) . '-' . $product->id,
                'sku' => $product->sku,
                'description' => $product->description,
                'short_description' => $product->short_description,
                'price' => (float) $product->price,
                'image' => $imageUrl,
                'gallery' => $gallery,
                'category' => $product->category ? [
                    'id' => $product->category->id,
                    'name' => $product->category->name,
                ] : null,
                'brand' => $product->brand ? [
                    'id' => $product->brand->id,
                    'name' => $product->brand->name,
                ] : null,
                'tags' => $product->meta_keywords 
                    ? array_map('trim', explode(',', $product->meta_keywords))
                    : [],
                'is_new_arrival' => (bool) $product->is_new_arrival,
                'is_preorder' => (bool) $product->is_preorder,
                'in_stock' => $effectiveStockQuantity > 0,
                'stock_quantity' => $effectiveStockQuantity,
                'unit' => $product->unit,
                'manufacturer' => $product->manufacturer,
                'is_merchandise' => (bool) $product->is_merchandise,
                'is_bundle' => (bool) $product->is_bundle,
                'bundle_type' => $product->bundle_type,
                'bundle_components' => $product->is_bundle
                    ? $product->bundleComponents->map(function ($component) {
                        return [
                            'id' => $component->id,
                            'quantity' => (int) $component->quantity,
                            'component_product' => $component->componentProduct ? [
                                'id' => $component->componentProduct->id,
                                'name' => $component->componentProduct->name,
                                'price' => (float) $component->componentProduct->price,
                                'image' => $this->getAbsoluteImageUrl($component->componentProduct->image),
                            ] : null,
                        ];
                    })->values()->all()
                    : [],
            ];
        });

        return response()->json($products);
    }

    /**
     * Get a single product by ID for product detail page.
     */
    public function show(int $id): JsonResponse
    {
        $query = Product::query()
            ->where('is_active', true)
            ->withSum('stockLevels as aggregated_stock_quantity', 'quantity')
            ->with(['category', 'brand', 'variants', 'bundleComponents.componentProduct']);

        $this->applyWebsiteVisibilityFilter($query, $request);
        
        $product = $query->findOrFail($id);

        // Convert image path to absolute URL
        $imageUrl = $this->getAbsoluteImageUrl($product->image);
        
        // Convert gallery images to absolute URLs
        $gallery = $product->gallery ?? [];
        if (is_array($gallery)) {
            $gallery = array_map(fn($img) => $this->getAbsoluteImageUrl($img), $gallery);
        }

        $effectiveStockQuantity = $product->track_inventory
            ? (int) ($product->aggregated_stock_quantity ?? 0)
            : (int) ($product->stock_quantity ?? 0);

        return response()->json([
            'id' => $product->id,
            'name' => $product->name,
            'secondary_name' => $product->secondary_name,
            'slug' => \Str::slug($product->name) . '-' . $product->id,
            'sku' => $product->sku,
            'description' => $product->description,
            'short_description' => $product->short_description,
            'price' => (float) $product->price,
            'image' => $imageUrl,
            'gallery' => $gallery,
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
            ] : null,
            'brand' => $product->brand ? [
                'id' => $product->brand->id,
                'name' => $product->brand->name,
            ] : null,
            'tags' => $product->meta_keywords 
                ? array_map('trim', explode(',', $product->meta_keywords))
                : [],
            'is_new_arrival' => (bool) $product->is_new_arrival,
            'is_preorder' => (bool) $product->is_preorder,
            'in_stock' => $effectiveStockQuantity > 0,
            'stock_quantity' => $effectiveStockQuantity,
            'unit' => $product->unit,
            'manufacturer' => $product->manufacturer,
            'is_merchandise' => (bool) $product->is_merchandise,
            'is_bundle' => (bool) $product->is_bundle,
            'bundle_type' => $product->bundle_type,
            'bundle_components' => $product->is_bundle
                ? $product->bundleComponents->map(function ($component) {
                    return [
                        'id' => $component->id,
                        'quantity' => (int) $component->quantity,
                        'component_product' => $component->componentProduct ? [
                            'id' => $component->componentProduct->id,
                            'name' => $component->componentProduct->name,
                            'price' => (float) $component->componentProduct->price,
                            'image' => $this->getAbsoluteImageUrl($component->componentProduct->image),
                        ] : null,
                    ];
                })->values()->all()
                : [],
            'attributes' => $product->attributes ?? [],
            'variants' => $product->variants ? $product->variants->map(function ($variant) {
                return [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'price' => (float) $variant->price,
                    'stock_quantity' => $variant->stock_quantity,
                    'attributes' => $variant->attributes ?? [],
                ];
            }) : [],
        ]);
    }

    /**
     * Get product categories that have website products.
     */
    public function categories(Request $request): JsonResponse
    {
        $query = \App\Models\ProductCategory::query()
            ->whereHas('products', function ($q) use ($request) {
                $q->where('is_active', true);
                $this->applyWebsiteVisibilityFilter($q, $request);
            })
            ->withCount(['products' => function ($q) use ($request) {
                $q->where('is_active', true);
                $this->applyWebsiteVisibilityFilter($q, $request);
            }]);
        
        $categories = $query->orderBy('name')
            ->get()
            ->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => \Str::slug($category->name),
                    'product_count' => $category->products_count,
                ];
            });

        return response()->json($categories);
    }
    
    /**
     * Get product tags (derived from meta_keywords).
     * Returns unique tags across all website-visible products.
     */
    public function tags(Request $request): JsonResponse
    {
        $query = Product::query()
            ->where('is_active', true)
            ->whereNotNull('meta_keywords')
            ->where('meta_keywords', '!=', '');

        $this->applyWebsiteVisibilityFilter($query, $request);
        
        $products = $query->pluck('meta_keywords');
        
        // Extract unique tags from comma-separated meta_keywords
        $tags = collect();
        foreach ($products as $keywords) {
            if (!$keywords) continue;
            $keywordArray = array_map('trim', explode(',', $keywords));
            foreach ($keywordArray as $keyword) {
                if (!empty($keyword)) {
                    $tags->push($keyword);
                }
            }
        }
        
        // Count occurrences and format
        $tagCounts = $tags->countBy()->map(function ($count, $name) {
            return [
                'name' => $name,
                'slug' => \Str::slug($name),
                'product_count' => $count,
            ];
        })->values()->sortByDesc('product_count')->values();
        
        return response()->json($tagCounts);
    }

    /**
     * Convert a relative image path to an absolute URL.
     * Uses APP_URL from config to ensure URLs always point to the backend storage.
     */
    private function getAbsoluteImageUrl(?string $imagePath): ?string
    {
        if (!$imagePath) {
            return null;
        }
        
        // Trim whitespace
        $imagePath = trim($imagePath);
        
        if (empty($imagePath)) {
            return null;
        }
        
        // Already an absolute URL - return as-is
        if (str_starts_with($imagePath, 'http://') || str_starts_with($imagePath, 'https://')) {
            return $imagePath;
        }
        
        // Handle data URLs (base64 images)
        if (str_starts_with($imagePath, 'data:')) {
            return $imagePath;
        }
        
        // Remove leading slash if present
        $imagePath = ltrim($imagePath, '/');
        
        // Always use APP_URL from config for consistent storage URLs
        $baseUrl = rtrim(config('app.url'), '/');
        
        // Handle various storage path formats
        if (str_starts_with($imagePath, 'storage/')) {
            return $baseUrl . '/' . $imagePath;
        }
        
        if (str_starts_with($imagePath, 'products/') || 
            str_starts_with($imagePath, 'images/') ||
            str_starts_with($imagePath, 'uploads/')) {
            return $baseUrl . '/storage/' . $imagePath;
        }
        
        if (str_starts_with($imagePath, 'public/storage/')) {
            return $baseUrl . '/' . str_replace('public/', '', $imagePath);
        }
        
        // Default: assume it's a path relative to storage
        return $baseUrl . '/storage/' . $imagePath;
    }

    private function applyWebsiteVisibilityFilter(Builder $query, ?Request $request = null): void
    {
        if ($request && $request->boolean('include_all_products')) {
            return;
        }

        if (!Schema::hasColumn('products', 'show_on_website')) {
            return;
        }

        $hasWebsiteProducts = Product::query()
            ->where('show_on_website', true)
            ->where('is_active', true)
            ->exists();

        if ($hasWebsiteProducts) {
            $query->where('show_on_website', true);
        }
    }
}
