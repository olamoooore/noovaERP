<?php

namespace Modules\Storefront\Shortcodes;

use Modules\Storefront\Services\CatalogQuery;

/**
 * The function set shipped by the module itself (FR-SF-01..04/07/08).
 * Other modules register their own Definitions against the registry
 * (Appendix B §B3.7) — the renderer core never changes to add a function
 * (NFR-10).
 *
 * Registry discipline (Appendix F §F6): a function change requires schema +
 * renderer + tests + reference in one PR.
 */
final class DefaultFunctions
{
    public static function register(StorefrontShortcodeRegistry $registry, CatalogQuery $catalog): void
    {
        $registry->register(self::products($catalog));
        $registry->register(self::product($catalog));
        $registry->register(self::categories($catalog));
        $registry->register(self::categoryProducts($catalog));
        $registry->register(self::search($catalog));

        // Tier B cart/account shells (FR-CO-01, FR-ID-01, FR-DASH-01).
        CartFunctions::register($registry);
        AccountFunctions::register($registry);
        CheckoutFunctions::register($registry);
    }

    // ─── Catalog (Tier A) ───────────────────────────────────────────────

    private static function products(CatalogQuery $catalog): Definition
    {
        return Definition::make('storefront.products')
            ->label('Product Grid')
            ->icon('solar:widget-2-bold-duotone')
            ->category('Catalog')
            ->tier(Tier::CACHEABLE)
            ->cache(300)
            ->version('1')
            ->help('A responsive product grid. Filter by ids, category, collection/tag, search, availability, or new arrivals.')
            ->example('[storefront.products category="Mugs" limit="8" columns="4"]')
            ->attributes(self::listingAttributes())
            ->render(static function (Context $c, array $a) use ($catalog): string {
                $result = $catalog->products($a, $c);

                return view('storefront::shortcodes.products', [
                    'context' => $c,
                    'items' => $result['items'],
                    'heading' => null,
                    'columns' => (int) $a['columns'],
                    'layout' => $a['layout'],
                    'productPage' => self::safeUrl($a['product_page'] ?? null),
                ])->render();
            });
    }

    /**
     * @return array<int,Attribute>
     */
    private static function listingAttributes(): array
    {
        return [
            Attribute::idList('ids', 100, 'Explicit product ids (comma-separated).'),
            Attribute::slug('category', null, 'Category name or id.'),
            Attribute::slug('collection', null, 'Tag/collection slug.'),
            Attribute::string('search', null, 80, 'Server-side search over name/SKU/short description.'),
            Attribute::enum('sort', ['newest', 'price_asc', 'price_desc', 'name'], 'newest'),
            Attribute::int('limit', 12, 1, 48, 'Max products to render.'),
            Attribute::int('page', 1, 1, 500, 'Page number for pagination.'),
            Attribute::enum('columns', [2, 3, 4, 5], 4),
            Attribute::enum('layout', ['grid', 'list'], 'grid'),
            Attribute::bool('in_stock_only', false, 'Hide out-of-stock products.'),
            Attribute::bool('new_only', false, 'Only new arrivals.'),
            Attribute::string('product_page', null, 500, 'Product page URL (renders card links).'),
        ];
    }

    private static function product(CatalogQuery $catalog): Definition
    {
        return Definition::make('storefront.product')
            ->label('Product Detail')
            ->icon('solar:box-bold-duotone')
            ->category('Catalog')
            ->tier(Tier::CACHEABLE)
            ->cache(300)
            ->version('1')
            ->help('A single product detail with variant selection and an add-to-cart control. By id/slug attribute or the ?product= URL parameter on merchant-built product pages.')
            ->example('[storefront.product id="12" layout="detail" show_related]')
            ->keyedBy(['product'])
            ->attributes([
                Attribute::int('id', 0, 1, 100000000, 'Product id (or use slug / ?product=).'),
                Attribute::slug('slug', null, 'Product slug.'),
                Attribute::enum('layout', ['card', 'detail'], 'detail'),
                Attribute::bool('show_related', false, 'Include related products by category.'),
                Attribute::int('related_limit', 4, 1, 12),
            ])
            ->render(static function (Context $c, array $a) use ($catalog): string {
                // Attribute wins; otherwise the bounded ?product= query param
                // (data for a registry-validated lookup — never a scope key).
                $id = ((int) $a['id']) ?: null;
                $slug = $a['slug'];

                // ?product= may carry an id OR a slug, so it cannot map to a
                // single typed attribute — the definition declares
                // keyedBy(['product']) and the closure resolves it.
                if ($id === null && $slug === null) {
                    $qp = (string) ($c->query['product'] ?? '');
                    if ($qp !== '') {
                        if (ctype_digit($qp)) {
                            $id = (int) $qp;
                        } elseif (preg_match('/^[a-z0-9_\-]{1,120}$/', $qp) === 1) {
                            $slug = $qp;
                        }
                    }
                }

                $item = $catalog->product($id, $slug, $c, withVariants: true);

                if ($item === null) {
                    return view('storefront::shortcodes.product-missing', ['context' => $c])->render();
                }

                $related = ($a['show_related'] && $id !== null)
                    ? $catalog->related($id, (int) $a['related_limit'], $c)
                    : [];

                return view('storefront::shortcodes.product', [
                    'context' => $c,
                    'item' => $item,
                    'layout' => $a['layout'],
                    'related' => $related,
                ])->render();
            });
    }

    private static function categories(CatalogQuery $catalog): Definition
    {
        return Definition::make('storefront.categories')
            ->label('Categories')
            ->icon('solar:folder-bold-duotone')
            ->category('Catalog')
            ->tier(Tier::CACHEABLE)
            ->cache(300)
            ->version('1')
            ->help('Category navigation with per-category product counts (visible catalog only).')
            ->example('[storefront.categories columns="3" category_page="/w/my-shop/collection"]')
            ->attributes([
                Attribute::slug('parent', null, 'Only children of this category (name or id).'),
                Attribute::enum('columns', [2, 3, 4], 3),
                Attribute::enum('layout', ['grid', 'list'], 'grid'),
                Attribute::bool('show_count', true),
                Attribute::string('category_page', null, 500, 'Category page URL — links become {page}?sfcat={name}.'),
            ])
            ->render(static function (Context $c, array $a) use ($catalog): string {
                return view('storefront::shortcodes.categories', [
                    'context' => $c,
                    'items' => $catalog->categories($c, $a['parent']),
                    'columns' => (int) $a['columns'],
                    'layout' => $a['layout'],
                    'showCount' => (bool) $a['show_count'],
                    'categoryPage' => self::safeUrl($a['category_page']),
                ])->render();
            });
    }

    private static function categoryProducts(CatalogQuery $catalog): Definition
    {
        return Definition::make('storefront.category_products')
            ->label('Category Products')
            ->icon('solar:layers-bold-duotone')
            ->category('Catalog')
            ->tier(Tier::CACHEABLE)
            ->cache(300)
            ->version('1')
            ->help('Products of one category. Category from the attribute or the ?sfcat= URL parameter (shared collection page).')
            ->example('[storefront.category_products category="Mugs" limit="12"]')
            ->fromQuery('sfcat', 'category')
            ->attributes(self::listingAttributes())
            ->render(static function (Context $c, array $a) use ($catalog): string {
                $category = (string) ($a['category'] ?? '');

                if ($category === '') {
                    return view('storefront::shortcodes.products', [
                        'context' => $c,
                        'items' => [],
                        'heading' => null,
                        'columns' => (int) $a['columns'],
                        'layout' => $a['layout'],
                        'productPage' => self::safeUrl($a['product_page'] ?? null),
                        'empty_message' => 'No collection selected on this page.',
                    ])->render();
                }

                $a['category'] = $category;
                $result = $catalog->products($a, $c);

                return view('storefront::shortcodes.products', [
                    'context' => $c,
                    'items' => $result['items'],
                    'heading' => (string) $category,
                    'columns' => (int) $a['columns'],
                    'layout' => $a['layout'],
                    'productPage' => self::safeUrl($a['product_page'] ?? null),
                ])->render();
            });
    }

    private static function search(CatalogQuery $catalog): Definition
    {
        return Definition::make('storefront.search')
            ->label('Search')
            ->icon('solar:magnifer-bold-duotone')
            ->category('Catalog')
            ->tier(Tier::CACHEABLE)
            ->cache(300)
            ->version('1')
            ->help('Search box; results render inline through the public catalog API (?sfq=). Initial results may be server-rendered via query="…".')
            ->example('[storefront.search placeholder="Search products…" limit="8"]')
            ->attributes([
                Attribute::string('placeholder', 'Search products…', 80),
                Attribute::string('target', null, 500, 'Results page URL (defaults to this page).'),
                Attribute::bool('inline_results', true, 'Show results under the box.'),
                Attribute::int('limit', 8, 1, 24),
                Attribute::string('query', null, 80, 'Pre-fill and render initial results.'),
            ])
            ->fromQuery('sfq', 'query')
            ->render(static function (Context $c, array $a) use ($catalog): string {
                $initial = (string) ($a['query'] ?? '');
                $results = [];

                if ($a['inline_results'] && $initial !== '') {
                    $results = $catalog->products(
                        ['search' => $initial, 'limit' => (int) $a['limit'], 'page' => 1],
                        $c
                    )['items'];
                }

                return view('storefront::shortcodes.search', [
                    'context' => $c,
                    'a' => $a,
                    'initial' => $initial,
                    'results' => $results,
                    'target' => self::safeUrl($a['target']),
                ])->render();
            });
    }

    /**
     * URL attributes are DATA: only same-site paths or http(s)/mailto
     * survive (§B3.5: never code, never javascript: navigation).
     */
    private static function safeUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        if (preg_match('#^(https?://|mailto:)[^\s"\'<>]+$#i', $url) === 1) {
            return $url;
        }

        return null;
    }
}
