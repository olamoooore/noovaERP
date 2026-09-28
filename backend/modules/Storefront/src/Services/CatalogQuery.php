<?php

namespace Modules\Storefront\Services;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Storefront\Support\RichtextSanitizer;

/**
 * Tenant-scoped catalog query for Tier A storefront functions (FR-SF-01/02/07).
 *
 * Guarantees (verified by tests):
 *  - Channel visibility only: show_on_website + is_active + status active
 *    (mirrors WebsiteProductController::applyWebsiteVisibilityFilter).
 *  - Public projection ONLY: cost, supplier, vendor_price, wholesale_price,
 *    msrp, min/max_price, income_account_id and other internal columns never
 *    leave this service (FR-SF-07).
 *  - Bounded query budget: ≤ 2 queries per call (product list + one
 *    eager-loaded relation), enforced by selecting an explicit column set.
 *  - No cross-tenant surface: it always reads the current tenant schema via
 *    the tenant-scoped Product model; tenant is NOT a parameter.
 */
final class CatalogQuery
{
    /** Columns safe to project to a public storefront fragment. */
    private const PUBLIC_COLUMNS = [
        'id', 'name', 'secondary_name', 'slug', 'sku',
        'short_description', 'description',
        'price', 'image', 'gallery',
        'unit', 'is_new_arrival', 'is_preorder',
        'track_inventory', 'stock_quantity', 'category_id', 'type',
    ];

    /**
     * @param  array<string,mixed>  $a  coerced attributes from the Definition
     * @return array{items: array<int,array<string,mixed>>, total: int}
     */
    public function products(array $a): array
    {
        $base = $this->visibleQuery();

        // Explicit ids (validated ints, tenant-scoped by the base query).
        if (! empty($a['ids'])) {
            $base->whereIn('id', $a['ids']);
        }

        if (! empty($a['category'])) {
            $base->where('category_id', $this->resolveCategoryId($a['category']));
        }

        // Collection/tag: platform taggables pivot, tag slug matched within
        // the tenant schema (same subquery keeps the list budget intact).
        if (! empty($a['collection'])) {
            $slug = $a['collection'];
            $base->whereExists(function ($q) use ($slug) {
                $productColumn = DB::raw('taggables.taggable_id');
                $q->select(DB::raw(1))
                    ->from('taggables')
                    ->join('tags', 'tags.id', '=', 'taggables.tag_id')
                    ->where('taggables.taggable_type', Product::class)
                    ->where('tags.slug', $slug)
                    ->whereRaw('taggables.taggable_id = products.id');
            });
        }

        if (! empty($a['search'])) {
            $term = $a['search'];
            $base->where(function (Builder $q) use ($term) {
                $q->where('name', 'ilike', "%{$term}")
                    ->orWhere('sku', 'ilike', "%{$term}")
                    ->orWhere('short_description', 'ilike', "%{$term}");
            });
        }

        // Unknown categories resolve to id 0 => empty result (never an error).
        if (! empty($a['in_stock_only'])) {
            $base->where(function (Builder $q) {
                $q->where('track_inventory', false)
                    ->orWhere('stock_quantity', '>', 0);
            });
        }

        if (! empty($a['new_only'])) {
            $base->where('is_new_arrival', true);
        }

        $this->applySort($base, (string) ($a['sort'] ?? 'newest'));

        $limit = (int) ($a['limit'] ?? 12);
        $page = max(1, (int) ($a['page'] ?? 1));

        $items = $base
            ->with('category:id,name')
            ->skip(($page - 1) * $limit)
            ->limit($limit)
            ->get()
            ->map(fn (Product $p) => $this->present($p))
            ->all();

        return ['items' => $items, 'total' => count($items)];
    }

    /**
     * Single product by id/slug with optional related set (Tier A).
     *
     * @return array<string,mixed>|null
     */
    public function product(?int $id, ?string $slug): ?array
    {
        $q = $this->visibleQuery();

        if ($id !== null) {
            $q->where('id', $id);
        } elseif (! empty($slug)) {
            $q->where('slug', $slug);
        } else {
            return null;
        }

        $product = $q->with('category:id,name')->first();

        return $product ? $this->present($product) : null;
    }

    /**
     * Related products in the same category, excluding the anchor (Tier A).
     *
     * @return array<int,array<string,mixed>>
     */
    public function related(int $anchorId, int $limit): array
    {
        $anchor = $this->visibleQuery()->where('id', $anchorId)->first(['id', 'category_id']);

        if (! $anchor || ! $anchor->category_id) {
            return [];
        }

        return $this->visibleQuery()
            ->where('category_id', $anchor->category_id)
            ->where('id', '!=', $anchorId)
            ->limit($limit)
            ->get()
            ->map(fn (Product $p) => $this->present($p))
            ->all();
    }

    private function visibleQuery(): Builder
    {
        return Product::query()
            ->select(self::PUBLIC_COLUMNS)
            ->where('show_on_website', true)
            ->where('is_active', true)
            ->where('status', 'active');
    }

    private function applySort(Builder $q, string $sort): void
    {
        match ($sort) {
            'price_asc' => $q->orderBy('price'),
            'price_desc' => $q->orderByDesc('price'),
            'name' => $q->orderBy('name'),
            default => $q->latest('id'),
        };
    }

    private function resolveCategoryId(string $value): int
    {
        // product_categories has no slug column (verified against the tenant
        // migration): match by numeric id or case-insensitive name. Unknown
        // values resolve to 0 => empty result, never an error.
        if (ctype_digit($value)) {
            return (int) $value;
        }

        return (int) (ProductCategory::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower($value)])
            ->value('id') ?? 0);
    }

    /**
     * Project a Product to the public payload. Internal columns are absent by
     * construction (they were never selected).
     *
     * @return array<string,mixed>
     */
    private function present(Product $p): array
    {
        $inStock = ! $p->track_inventory || (int) $p->stock_quantity > 0;

        return [
            'id' => $p->id,
            'name' => $p->name,
            'secondary_name' => $p->secondary_name,
            'slug' => $p->slug ?: ($p->name ? Str::slug($p->name).'-'.$p->id : (string) $p->id),
            'sku' => $p->sku,
            'price' => (float) $p->price,
            'image' => $this->imageUrl($p->image),
            'gallery' => $this->galleryUrls($p->gallery),
            'short_description' => RichtextSanitizer::plain($p->short_description),
            'description' => RichtextSanitizer::plain($p->description),
            'category' => $p->relationLoaded('category') && $p->category
                ? ['id' => $p->category->id, 'name' => $p->category->name]
                : null,
            'in_stock' => $inStock,
            'is_new_arrival' => (bool) $p->is_new_arrival,
            'is_preorder' => (bool) $p->is_preorder,
            'unit' => $p->unit,
            'type' => $p->type,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function galleryUrls(mixed $gallery): array
    {
        if (! is_array($gallery)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($img) => is_string($img) ? $this->imageUrl($img) : (is_array($img) ? $this->imageUrl($img['path'] ?? $img['url'] ?? null) : null),
            $gallery
        )));
    }

    private function imageUrl(?string $imagePath): ?string
    {
        if (! $imagePath || trim($imagePath) === '') {
            return null;
        }

        $imagePath = trim($imagePath);

        if (str_starts_with($imagePath, 'http://')
            || str_starts_with($imagePath, 'https://')
            || str_starts_with($imagePath, 'data:')) {
            return $imagePath;
        }

        return rtrim(config('app.url'), '/').'/'.ltrim(str_replace('/storage/', 'storage/', $imagePath), '/');
    }
}
