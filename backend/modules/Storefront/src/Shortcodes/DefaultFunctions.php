<?php

namespace Modules\Storefront\Shortcodes;

use Modules\Storefront\Services\CatalogQuery;

/**
 * The pilot function set shipped by the module (P0: FR-SF-01/02/07).
 * Other modules register their own Definitions against the registry
 * (Appendix B §B3.7) — the renderer core never changes to add a function
 * (NFR-10).
 *
 * Every Definition here is Tier A (anonymous-safe, cacheable). Nothing
 * personal is rendered server-side. Registry discipline (Appendix F §F6): a
 * function change requires schema + renderer + tests + reference in one PR.
 */
final class DefaultFunctions
{
    public static function register(StorefrontShortcodeRegistry $registry, CatalogQuery $catalog): void
    {
        $registry->register(self::products($catalog));
        $registry->register(self::product($catalog));

        // Tier B cart/account shells (FR-CO-01, FR-ID-01, FR-DASH-01).
        CartFunctions::register($registry);
        AccountFunctions::register($registry);
    }

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
            ->example('[storefront.products category="mugs" limit="8" columns="4"]')
            ->attributes([
                Attribute::idList('ids', 100, 'Explicit product ids (comma-separated).'),
                Attribute::slug('category', null, 'Category slug or id.'),
                Attribute::slug('collection', null, 'Tag/collection slug.'),
                Attribute::string('search', null, 80, 'Server-side search over name/SKU/short description.'),
                Attribute::enum('sort', ['newest', 'price_asc', 'price_desc', 'name'], 'newest'),
                Attribute::int('limit', 12, 1, 48, 'Max products to render.'),
                Attribute::int('page', 1, 1, 500, 'Page number for pagination.'),
                Attribute::enum('columns', [2, 3, 4, 5], 4),
                Attribute::enum('layout', ['grid', 'list'], 'grid'),
                Attribute::bool('in_stock_only', false, 'Hide out-of-stock products.'),
                Attribute::bool('new_only', false, 'Only new arrivals.'),
            ])
            ->render(static function (Context $c, array $a) use ($catalog): string {
                $result = $catalog->products($a);

                return view('storefront::shortcodes.products', [
                    'context' => $c,
                    'items' => $result['items'],
                    'columns' => (int) $a['columns'],
                    'layout' => $a['layout'],
                ])->render();
            });
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
            ->help('A single product card/detail by id or slug. Optionally show related products.')
            ->example('[storefront.product slug="classic-mug-12" layout="detail" show_related]')
            ->attributes([
                Attribute::int('id', null, 1, 100000000, 'Product id (or use slug).'),
                Attribute::slug('slug', null, 'Product slug.'),
                Attribute::enum('layout', ['card', 'detail'], 'detail'),
                Attribute::bool('show_related', false, 'Include related products by category.'),
                Attribute::int('related_limit', 4, 1, 12),
            ])
            ->render(static function (Context $c, array $a) use ($catalog): string {
                // Attribute::int coerces absent values to 0 — treat 0 as unset.
                $id = ((int) $a['id']) ?: null;

                $item = $catalog->product($id, $a['slug']);

                if ($item === null) {
                    return view('storefront::shortcodes.product-missing', ['context' => $c])->render();
                }

                $related = ($a['show_related'] && $id !== null)
                    ? $catalog->related($id, (int) $a['related_limit'])
                    : [];

                return view('storefront::shortcodes.product', [
                    'context' => $c,
                    'item' => $item,
                    'layout' => $a['layout'],
                    'related' => $related,
                ])->render();
            });
    }
}
