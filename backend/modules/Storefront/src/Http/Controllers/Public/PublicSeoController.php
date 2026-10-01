<?php

declare(strict_types=1);

namespace Modules\Storefront\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\WebsiteBuilder\Models\Page;
use Modules\WebsiteBuilder\Models\Website;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEO crawl surface (NFR-08, FR-SF-12, Appendix-F P5 "SEO/perf polish").
 *
 * Served on the tenant's own host (platform subdomain or verified custom
 * domain — InitializeTenantForPublicAccess resolves tenancy before these
 * routes run and fails closed with 404 for anything else), so the sitemap
 * can only ever expose the resolving tenant's own published URLs.
 */
class PublicSeoController extends Controller
{
    private const MAX_PAGES_PER_SITE = 100;

    private const MAX_PRODUCT_URLS = 2000;

    public function sitemap(Request $request): Response
    {
        // tenant() reads the container binding — correct both when the
        // middleware initialized tenancy (production) and when a test bound
        // a tenant instance directly (tenancy()->tenant property stays stale
        // there — same helper the rest of the module uses).
        $tenant = tenant();
        $tenantId = $tenant ? (string) $tenant->getTenantKey() : '';

        if ($tenantId === '') {
            return $this->xml($this->urlset([]), 404);
        }

        $host = strtolower($request->getHost());
        $base = $request->getSchemeAndHttpHost();

        $body = Cache::remember(
            "storefront:sitemap:{$tenantId}:{$host}",
            now()->addMinutes(10),
            fn () => $this->buildSitemap($base)
        );

        return $this->xml($body)
            ->header('X-Robots-Tag', 'noindex'); // the XML itself needn't be indexed
    }

    public function robots(Request $request): Response
    {
        $base = $request->getSchemeAndHttpHost();

        // Middleware already fail-closed unresolved hosts; reaching here
        // means a tenant is bound. A tenant with no published website asks
        // crawlers to stay away entirely.
        $hasPublishedSite = Website::where('status', 'published')->exists();

        if (! $hasPublishedSite) {
            return response("User-agent: *\nDisallow: /\n", 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'X-Robots-Tag' => 'noindex',
            ]);
        }

        $body = implode("\n", [
            'User-agent: *',
            'Allow: /w/',
            'Disallow: /api/',
            'Disallow: /storefront/account',
            'Disallow: /storefront/cart',
            'Disallow: /storefront/checkout',
            '',
            'Sitemap: '.$base.'/sitemap.xml',
            '',
        ]);

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * @return string sitemap XML document
     */
    private function buildSitemap(string $base): string
    {
        $urls = [];
        $seen = [];

        $websites = Website::where('status', 'published')->orderBy('id')->limit(20)->get();

        foreach ($websites as $website) {
            $sitePath = '/w/'.$website->slug;

            $pages = Page::where('website_id', $website->id)
                ->where('status', 'published')
                ->orderBy('sort_order')
                ->limit(self::MAX_PAGES_PER_SITE)
                ->get();

            $productDetailSlug = null;

            foreach ($pages as $page) {
                $path = $page->is_home ? $sitePath : $sitePath.'/'.$page->slug;
                $loc = $base.$path;

                if (! isset($seen[$loc])) {
                    $seen[$loc] = true;
                    $urls[] = [
                        'loc' => $loc,
                        'lastmod' => $this->isoDate($page->updated_at),
                    ];
                }

                // The page hosting the single-product function is what
                // ?product= URLs belong on (mirrors the renderer's own
                // canonical rule — one page per product view).
                $html = (string) ($page->servedVersion()?->content['html'] ?? '');
                if ($productDetailSlug === null && preg_match('/\[storefront\.product(\s|\])/', $html) === 1) {
                    $productDetailSlug = $page->is_home ? '' : $page->slug;
                }
            }

            if ($productDetailSlug !== null) {
                // Mirror the card href shape exactly (?product=<id>) so the
                // sitemap lists the SAME URLs the site itself links to.
                $products = DB::table('products')
                    ->where('show_on_website', true)
                    ->where('is_active', true)
                    ->where(function ($w) {
                        $w->whereNull('status')->orWhere('status', 'active');
                    })
                    ->orderBy('id')
                    ->limit(self::MAX_PRODUCT_URLS)
                    ->get(['id', 'updated_at']);

                $detailPath = $productDetailSlug === '' ? $sitePath : $sitePath.'/'.$productDetailSlug;

                foreach ($products as $product) {
                    $loc = $base.$detailPath.'?product='.(int) $product->id;

                    if (isset($seen[$loc])) {
                        continue;
                    }
                    $seen[$loc] = true;

                    $urls[] = [
                        'loc' => $loc,
                        'lastmod' => $this->isoDate($product->updated_at),
                    ];
                }
            }
        }

        return $this->urlset($urls);
    }

    /**
     * @param  list<array{loc: string, lastmod: string|null}>  $urls
     */
    private function urlset(array $urls): string
    {
        $xml = new \SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"/>'
        );

        foreach ($urls as $url) {
            $node = $xml->addChild('url');
            $node->addChild('loc', htmlspecialchars($url['loc'], ENT_QUOTES, 'UTF-8'));

            if ($url['lastmod'] !== null) {
                $node->addChild('lastmod', $url['lastmod']);
            }
        }

        $dom = dom_import_simplexml($xml)->ownerDocument;
        $dom->formatOutput = true;

        return $dom->saveXML();
    }

    private function isoDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_string($value) && $value !== '') {
            $ts = strtotime($value);

            return $ts === false ? null : date(DATE_ATOM, $ts);
        }

        return null;
    }

    private function xml(string $body, int $status = 200): Response
    {
        if ($status === 404) {
            Log::info('Storefront: sitemap requested without tenant context');
        }

        return response($body, $status, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
