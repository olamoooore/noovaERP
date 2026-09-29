<?php

namespace Modules\Storefront\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Storefront\Services\CatalogQuery;
use Modules\Storefront\Shortcodes\StorefrontShortcodeRegistry;

/**
 * Public catalog JSON feed (Appendix E §E4.1) — the runtime API mirror of
 * the `[storefront.products]` shortcode. It reuses the SAME CatalogQuery
 * service (identical visibility rules + public projection + bounds), so the
 * API can never leak fields the shortcode renderer hides (SOP §4: no
 * duplicated catalog logic).
 */
final class CatalogController extends Controller
{
    public function __construct(
        private readonly CatalogQuery $catalog,
        private readonly StorefrontShortcodeRegistry $registry,
    ) {}

    public function products(Request $request): JsonResponse
    {
        $definition = $this->registry->get('storefront.products');
        abort_if($definition === null, 500, 'storefront.products missing from registry');

        $raw = $request->only(['ids', 'category', 'collection', 'search', 'sort', 'limit', 'page', 'in_stock_only', 'new_only']);

        // Same coercion pipeline as the renderer (typed, bounded, hostile-safe).
        ['values' => $attrs] = $definition->validate($raw);

        $result = $this->catalog->products($attrs, new \Modules\Storefront\Shortcodes\Context(
            tenantId: (string) (function_exists('tenant') && tenant() ? tenant('id') : ''),
            locale: (string) app()->getLocale(),
        ));

        return response()->json([
            'data' => $result['items'],
            'meta' => [
                'count' => $result['total'],
                'page' => (int) $attrs['page'],
                'limit' => (int) $attrs['limit'],
                'currency' => \Modules\Storefront\Models\StorefrontSettings::current()->currency_code,
            ],
        ]);
    }
}
