<?php

namespace Modules\Storefront\Services;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Storefront\Models\StorefrontSettings;
use Modules\Storefront\Shortcodes\Context;
use Modules\Storefront\Support\RichtextSanitizer;

/**
 * Tenant-scoped catalog query for storefront functions (FR-SF-01..04/07/08/09).
 *
 * Guarantees (verified by tests):
 *  - Channel visibility only: show_on_website + is_active + status active
 *    (STRICT — no legacy-style include_all_products bypass exists here);
 *  - Public projection ONLY: cost, supplier, vendor_price, wholesale_price,
 *    msrp, min/max_price, income_account_id and other internal columns never
 *    leave this service (FR-SF-07);
 *  - Availability expressed via TENANT POLICY LABELS (in stock / low stock /
 *    backorder / out of stock) — raw internal counts never rendered (FR-SF-09);
 *  - Bounded query budgets: grid ≤ 2 (list+eager category), single ≤ 3
 *    (+variant batch), categories ≤ 2 (one withCount + one list);
 *  - No cross-tenant surface: always reads the current tenant schema; the
 *    tenant is never a parameter.
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

    private const VARIANT_COLUMNS = ['id', 'product_id', 'name', 'sku', 'price', 'stock_quantity', 'track_inventory'];

    /**
     * @param  array<string,mixed>  $a  coerced attributes from the Definition
     * @return array{items: array<int,array<string,mixed>>, total: int}
     */
    public function products(array $a, Context $ctx): array
    {
        $settings = $ctx->settings();
        $base = $this->visibleQuery();

        if (! empty($a['ids'])) {
            $base->whereIn('id', $a['ids']);
        }

        if (! empty($a['category'])) {
            $base->where('category_id', $this->resolveCategoryId($a['category']));
        }

        if (! empty($a['collection'])) {
            $slug = $a['collection'];
            $base->whereExists(function ($q) use ($slug) {
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
            ->map(fn (Product $p) => $this->present($p, $settings))
            ->all();

        return ['items' => $items, 'total' => count($items)];
    }

    /**
     * Single product by id/slug (or the bounded ?product= query param on
     * merchant-built product pages), with its purchaseable variants when
     * requested (FR-SF-08).
     *
     * @return array<string,mixed>|null
     */
    public function product(?int $id, ?string $slug, Context $ctx, bool $withVariants = false): ?array
    {
        $settings = $ctx->settings();
        $q = $this->visibleQuery();

        if ($id !== null) {
            $q->where('id', $id);
        } elseif (! empty($slug)) {
            $q->where('slug', $slug);
        } else {
            return null;
        }

        $product = $q->with('category:id,name')->first();

        if ($product === null) {
            return null;
        }

        $variants = [];
        if ($withVariants) {
            $variants = ProductVariant::query()
                ->select(self::VARIANT_COLUMNS)
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->orderBy('id')
                ->get()
                ->map(fn (ProductVariant $v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'sku' => $v->sku,
                    'price' => (float) $v->price,
                    'availability' => $this->availabilityLabel($product, $v, $settings)['key'],
                ])
                ->all();
        }

        return $this->present($product, $settings, $variants);
    }

    /**
     * Category navigation with per-category visible-product counts.
     *
     * @return array<int,array<string,mixed>>
     */
    public function categories(Context $ctx, ?string $parent = null): array
    {
        $query = ProductCategory::query()
            ->where('is_active', true)
            ->withCount(['products as product_count' => fn (Builder $q) => $this->applyVisibleScope($q)]);

        if (! empty($parent)) {
            $parentId = $this->resolveCategoryId($parent);
            if ($parentId === 0) {
                return [];
            }
            $query->where('parent_id', $parentId);
        } else {
            // Default view: top-level categories only.
            $query->whereNull('parent_id');
        }

        return $query->orderBy('name')->get()
            ->map(fn (ProductCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'product_count' => (int) $c->product_count,
            ])
            ->all();
    }

    /**
     * Related products in the same category, excluding the anchor.
     *
     * @return array<int,array<string,mixed>>
     */
    public function related(int $anchorId, int $limit, Context $ctx): array
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
            ->map(fn (Product $p) => $this->present($p, $ctx->settings()))
            ->all();
    }

    // ─── Internals ──────────────────────────────────────────────────────

    private function visibleQuery(): Builder
    {
        return $this->applyVisibleScope(Product::query()->select(self::PUBLIC_COLUMNS));
    }

    /**
     * THE visibility scope — reused by products and the categories counter so
     * the two can never drift.
     */
    private function applyVisibleScope(Builder $q): Builder
    {
        return $q
            ->where('show_on_website', true)
            ->where('is_active', true)
            ->where(function (Builder $w) {
                $w->whereNull('status')->orWhere('status', Product::STATUS_ACTIVE);
            });
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
     * Tenant-policy availability labels (FR-SF-09) — counts never leak.
     *
     * @return array{key:string,label:string}
     */
    private function availabilityLabel(Product $p, ?ProductVariant $variant, StorefrontSettings $settings): array
    {
        $track = $variant?->track_inventory ?? $p->track_inventory;

        if (! $track) {
            return ['key' => 'available', 'label' => 'Available'];
        }

        $qty = (int) ($variant?->stock_quantity ?? $p->stock_quantity);

        if ($qty <= 0) {
            return $settings->allow_backorder
                ? ['key' => 'backorder', 'label' => 'Available on backorder']
                : ['key' => 'out', 'label' => 'Out of stock'];
        }

        if ((int) $settings->low_stock_threshold > 0 && $qty <= (int) $settings->low_stock_threshold) {
            return ['key' => 'low', 'label' => 'Low stock'];
        }

        return ['key' => 'in', 'label' => 'In stock'];
    }

    /**
     * Project a Product to the public payload. Internal columns are absent by
     * construction (they were never selected).
     *
     * @param  array<int,array<string,mixed>>  $variants
     * @return array<string,mixed>
     */
    private function present(Product $p, StorefrontSettings $settings, array $variants = []): array
    {
        $availability = $this->availabilityLabel($p, null, $settings);

        return [
            'id' => $p->id,
            'name' => $p->name,
            'secondary_name' => $p->secondary_name,
            'slug' => $p->slug ?: (Str::slug((string) $p->name).'-'.$p->id),
            'sku' => $p->sku,
            'price' => (float) $p->price,
            'image' => $this->imageUrl($p->image),
            'gallery' => $this->galleryUrls($p->gallery),
            'short_description' => RichtextSanitizer::plain($p->short_description),
            'description' => RichtextSanitizer::plain($p->description),
            'category' => $p->relationLoaded('category') && $p->category
                ? ['id' => $p->category->id, 'name' => $p->category->name]
                : null,
            'in_stock' => ! $p->track_inventory || (int) $p->stock_quantity > 0 || $settings->allow_backorder,
            'availability' => $availability['key'],
            'availability_label' => $availability['label'],
            'is_new_arrival' => (bool) $p->is_new_arrival,
            'is_preorder' => (bool) $p->is_preorder,
            'unit' => $p->unit,
            'type' => $p->type,
            'variants' => $variants,
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
