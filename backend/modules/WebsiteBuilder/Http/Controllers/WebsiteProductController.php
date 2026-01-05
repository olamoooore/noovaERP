<?php

namespace Modules\WebsiteBuilder\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Controller for public website product API.
 * Returns products marked with show_on_website = true for e-commerce websites.
 */
class WebsiteProductController extends Controller
{
    /**
     * Get all products visible on website.
     */
    public function index(Request $request): JsonResponse
    {
        // Check if any products have show_on_website enabled
        $hasWebsiteProducts = Product::where('show_on_website', true)->where('is_active', true)->exists();
        
        $query = Product::query()
            ->where('is_active', true)
            ->with(['category', 'brand'])
            ->orderBy('name');
        
        // Only filter by show_on_website if some products have it enabled
        // This allows the shop to work even before products are marked for website
        if ($hasWebsiteProducts) {
            $query->where('show_on_website', true);
        }

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
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('secondary_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'name');
        $sortDir = strtolower($request->input('sort_dir', 'asc'));
        $allowedSorts = ['name', 'price', 'created_at'];
        
        if (in_array($sortBy, $allowedSorts)) {
            $query->reorder($sortBy, in_array($sortDir, ['asc', 'desc']) ? $sortDir : 'asc');
        }

        // Pagination
        $perPage = min(max((int) $request->input('per_page', 12), 1), 100);
        $products = $query->paginate($perPage);

        // Transform for public API (hide sensitive fields)
        $products->getCollection()->transform(function ($product) {
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
                'is_new_arrival' => (bool) $product->is_new_arrival,
                'is_preorder' => (bool) $product->is_preorder,
                'in_stock' => $product->stock_quantity > 0,
                'stock_quantity' => $product->stock_quantity,
                'unit' => $product->unit,
            ];
        });

        return response()->json($products);
    }

    /**
     * Get a single product by ID for product detail page.
     */
    public function show(int $id): JsonResponse
    {
        // Check if any products have show_on_website enabled
        $hasWebsiteProducts = Product::where('show_on_website', true)->where('is_active', true)->exists();
        
        $query = Product::query()
            ->where('is_active', true)
            ->with(['category', 'brand', 'variants']);
        
        // Only filter by show_on_website if some products have it enabled
        if ($hasWebsiteProducts) {
            $query->where('show_on_website', true);
        }
        
        $product = $query->findOrFail($id);

        // Convert image path to absolute URL
        $imageUrl = $this->getAbsoluteImageUrl($product->image);
        
        // Convert gallery images to absolute URLs
        $gallery = $product->gallery ?? [];
        if (is_array($gallery)) {
            $gallery = array_map(fn($img) => $this->getAbsoluteImageUrl($img), $gallery);
        }

        return response()->json([
            'id' => $product->id,
            'name' => $product->name,
            'secondary_name' => $product->secondary_name,
            'slug' => \Str::slug($product->name) . '-' . $product->id,
            'sku' => $product->sku,
            'description' => $product->description,
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
            'is_new_arrival' => (bool) $product->is_new_arrival,
            'is_preorder' => (bool) $product->is_preorder,
            'in_stock' => $product->stock_quantity > 0,
            'stock_quantity' => $product->stock_quantity,
            'unit' => $product->unit,
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
    public function categories(): JsonResponse
    {
        // Check if any products have show_on_website enabled
        $hasWebsiteProducts = Product::where('show_on_website', true)->where('is_active', true)->exists();
        
        $query = \App\Models\ProductCategory::query();
        
        if ($hasWebsiteProducts) {
            $query->whereHas('products', function ($q) {
                $q->where('show_on_website', true)->where('is_active', true);
            })
            ->withCount(['products' => function ($q) {
                $q->where('show_on_website', true)->where('is_active', true);
            }]);
        } else {
            // Fallback: show all categories with active products
            $query->whereHas('products', function ($q) {
                $q->where('is_active', true);
            })
            ->withCount(['products' => function ($q) {
                $q->where('is_active', true);
            }]);
        }
        
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
     * Convert a relative image path to an absolute URL.
     * Uses the Origin header to generate URLs that work in preview context.
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
        
        // Get the base URL from request headers
        // Priority: Origin > X-Forwarded-Host > Referer > Request Host
        $request = request();
        $baseUrl = null;
        
        // 1. Try Origin header (best for CORS requests)
        $origin = $request->header('Origin');
        if ($origin && !str_contains($origin, 'blob:')) {
            $baseUrl = rtrim($origin, '/');
        }
        
        // 2. Try X-Forwarded-Host (for proxy scenarios)
        if (!$baseUrl) {
            $forwardedHost = $request->header('X-Forwarded-Host');
            $forwardedProto = $request->header('X-Forwarded-Proto', 'http');
            if ($forwardedHost) {
                $baseUrl = $forwardedProto . '://' . $forwardedHost;
            }
        }
        
        // 3. Try Referer header
        if (!$baseUrl) {
            $referer = $request->header('Referer');
            if ($referer && !str_contains($referer, 'blob:')) {
                $parsed = parse_url($referer);
                if (isset($parsed['scheme']) && isset($parsed['host'])) {
                    $baseUrl = $parsed['scheme'] . '://' . $parsed['host'];
                    if (isset($parsed['port'])) {
                        $baseUrl .= ':' . $parsed['port'];
                    }
                }
            }
        }
        
        // 4. Fallback to localhost:5173 for development (Vite dev server)
        if (!$baseUrl) {
            // Check if we're in development mode
            $appEnv = config('app.env', 'production');
            if ($appEnv === 'local' || $appEnv === 'development') {
                $baseUrl = 'http://localhost:5173';
            } else {
                // Production fallback
                $scheme = $request->secure() ? 'https' : 'http';
                $host = $request->getHost();
                $port = $request->getPort();
                
                $baseUrl = $scheme . '://' . $host;
                if (($scheme === 'http' && $port != 80) || ($scheme === 'https' && $port != 443)) {
                    $baseUrl .= ':' . $port;
                }
            }
        }
        
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
}
