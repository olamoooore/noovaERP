<?php

declare(strict_types=1);

namespace Modules\Storefront\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Storefront\Models\StorefrontSettings;
use Modules\Storefront\Services\StorefrontCache;
use Modules\WebsiteBuilder\Models\Page;
use Modules\WebsiteBuilder\Models\Website;

/**
 * Operational health for the storefront surface (Appendix E §E4.4
 * `…/health`; A-27 "cache hit ratio reported"). Read-only — every figure
 * comes from live state, nothing is maintained by this endpoint.
 */
final class HealthController extends Controller
{
    public function __construct(
        private readonly StorefrontCache $cache,
    ) {}

    public function show(): JsonResponse
    {
        $tenant = tenant();
        $tenantId = $tenant ? (string) $tenant->getTenantKey() : '';
        $settings = StorefrontSettings::current();

        $publishedSite = Website::where('status', 'published')->orderBy('id')->first();

        return response()->json([
            'data' => [
                'storefront_enabled' => (bool) $settings->enabled,
                'currency_code' => $settings->currency_code,
                'cache' => $this->cache->stats($tenantId) + [
                    'version' => $tenantId === '' ? null : $this->cache->currentVersion($tenantId),
                ],
                'website' => $publishedSite ? [
                    'slug' => $publishedSite->slug,
                    'published_pages' => Page::where('website_id', $publishedSite->id)
                        ->where('status', 'published')
                        ->count(),
                ] : null,
                'sitemap_ready' => $publishedSite !== null,
            ],
        ]);
    }
}
