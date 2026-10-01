<?php

namespace Modules\Storefront\Shortcodes;

use App\Contracts\PageContentFilter;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\Log;
use Modules\Storefront\Models\StorefrontSettings;
use Throwable;

/**
 * The Storefront implementation of the platform page-content extension point.
 * WebsiteBuilder's /w/{slug} renderer applies every tagged
 * 'page_content_filters' implementation — this is the single render path
 * for storefront tokens (Appendix B §B8 "one renderer, no parallel path").
 *
 * Governance (FR-MOD-02/04, A-04):
 *  - not entitled for `storefront` → tokens are STRIPPED (published pages
 *    never fatal and never leak raw tokens to visitors);
 *  - merchant disabled the shop (`storefront_settings.enabled = false`)
 *    → same graceful strip;
 *  - entitlement resolution failure → fail closed (strip), never fail open.
 */
final class StorefrontPageContentFilter implements PageContentFilter
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly FeatureGateService $featureGate,
        private readonly \Modules\Storefront\Services\StorefrontCache $cache = new \Modules\Storefront\Services\StorefrontCache,
    ) {}

    public function filterHtml(string $html, array $context): string
    {
        $tenantId = $this->currentTenantId();

        if ($tenantId === null) {
            // No tenancy context at all — nothing can be rendered safely.
            return $this->renderer->stripTokens($html);
        }

        if (! $this->storefrontActive($tenantId)) {
            return $this->renderer->stripTokens($html);
        }

        $before = $this->cache->stats($tenantId);
        $startedAt = hrtime(true);

        $rendered = $this->renderer->render($html, new Context(
            tenantId: $tenantId,
            mode: Context::MODE_PUBLIC,
            pageId: isset($context['page_id']) ? (int) $context['page_id'] : null,
            pageVersionId: isset($context['page_version_id']) ? (int) $context['page_version_id'] : null,
            websiteSlug: (string) ($context['website_slug'] ?? ''),
            locale: (string) ($context['locale'] ?? app()->getLocale()),
            query: (array) ($context['query'] ?? []),
        ));

        // NFR-09/§B8 per-render observability (A-27 cache-ratio source).
        $after = $this->cache->stats($tenantId);
        Log::info('Storefront: page render', [
            'tenant_id' => $tenantId,
            'page_id' => $context['page_id'] ?? null,
            'page_version_id' => $context['page_version_id'] ?? null,
            'website_slug' => (string) ($context['website_slug'] ?? ''),
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000, 1),
            'cache_hits' => $after['hits'] - $before['hits'],
            'cache_misses' => $after['misses'] - $before['misses'],
        ]);

        return $this->injectRuntime($rendered);
    }

    /**
     * One versioned, tenant-neutral runtime bundle (FR-PLAT-06 / Appendix B
     * §B5): injected once, only when the rendered page actually contains
     * Tier B islands. defer + external src only — no inline JS, CSP-safe.
     */
    private function injectRuntime(string $html): string
    {
        if (! str_contains($html, 'data-sf-island') || str_contains($html, '/storefront/assets/')) {
            return $html;
        }

        $version = \Modules\Storefront\Support\StorefrontRuntime::VERSION;
        $base = '/storefront/assets/'.$version;

        $tags = '<link rel="stylesheet" href="'.$base.'/storefront.css">'."\n"
            .'<script src="'.$base.'/storefront.js" defer data-sf-runtime="'.$version.'"></script>';

        // After the last island's markup so the defer script still loads
        // before first paint of interactivity; append is fine because this
        // fragment is injected into <body> by the page renderer.
        return $html."\n".$tags;
    }

    private function storefrontActive(string $tenantId): bool
    {
        try {
            $entitled = $this->featureGate->check($tenantId, 'storefront')['is_entitled'] ?? false;
        } catch (Throwable $e) {
            Log::warning('Storefront: entitlement check failed on public render, stripping functions', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (! $entitled) {
            return false;
        }

        try {
            return StorefrontSettings::current()->enabled;
        } catch (Throwable $e) {
            // Table missing (module not migrated for this tenant yet) → the
            // shop cannot render; degrade silently rather than fatalt the page.
            Log::warning('Storefront: settings unavailable on public render, stripping functions', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function currentTenantId(): ?string
    {
        if (! function_exists('tenant') || ! tenant()) {
            return null;
        }

        $id = tenant('id');

        return ($id === null || $id === '') ? null : (string) $id;
    }
}
