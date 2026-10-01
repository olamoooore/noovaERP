<?php

namespace Modules\Storefront\Shortcodes;

use Illuminate\Support\Facades\Log;
use Modules\Storefront\Services\StorefrontCache;
use Throwable;

/**
 * Shortcode renderer (Appendix B §B3.3). Runs tokens through the registry,
 * coerces attributes, applies the Tier A/B cache policy and the error policy:
 *
 *  - production: a failing / unknown function logs once (with page id) and
 *    renders NOTHING — the surrounding page still returns 200 (never fatal).
 *  - preview/editor: the same failure renders a visible, escaped notice so a
 *    merchant sees why a block is empty before publishing (§B3.5 rule 6).
 *  - Tier A fragments are cached per tenant + page version + attrs; Tier B
 *    never touch the shared cache (§B3.4).
 */
final class Renderer
{
    public function __construct(
        private readonly StorefrontShortcodeRegistry $registry,
        private readonly Parser $parser = new Parser,
        private readonly StorefrontCache $cache = new StorefrontCache,
    ) {}

    /**
     * Render every storefront token found in $html for the given context.
     */
    public function render(string $html, Context $context): string
    {
        if (! $this->parser->hasTokens($html)) {
            return $html;
        }

        return $this->parser->replace(
            $html,
            fn (array $token, string $raw) => $this->renderToken($token['function'], $token['attributes'], $context, $raw),
        );
    }

    /**
     * Replace every token with nothing — the disabled/unentitled degradation
     * path (visitors must never see raw tokens; FR-BLD-05/FR-MOD-02).
     */
    public function stripTokens(string $html): string
    {
        if (! $this->parser->hasTokens($html)) {
            return $html;
        }

        return $this->parser->replace($html, static fn (array $token, string $raw) => '');
    }

    /**
     * Render a single (already-parsed) token. Unknown/failed functions follow
     * the tier-aware error policy; nothing here throws to the caller.
     *
     * @param  array<string,mixed>  $rawAttributes
     */
    public function renderToken(string $functionKey, array $rawAttributes, Context $context, string $rawToken = ''): string
    {
        $definition = $this->registry->get($functionKey);

        if ($definition === null) {
            Log::warning('Storefront: unknown shortcode function', [
                'function' => $functionKey,
                'tenant_id' => $context->tenantId,
                'page_id' => $context->pageId,
            ]);

            return $this->fail($context, "Unknown storefront function '{$functionKey}'.");
        }

        ['values' => $attributes, 'ignored' => $ignored] = $definition->validate($rawAttributes);

        // Query-parameter bindings (e.g. ?sfcat= on a shared collection page)
        // resolve INTO the attribute set BEFORE the cache key is computed —
        // otherwise one visitor's query result would be served to another.
        foreach ($definition->queryBinds() as $queryKey => $attributeName) {
            $attribute = $definition->attributeDefinitions()[$attributeName] ?? null;
            if ($attribute === null || ! array_key_exists($queryKey, $context->query)) {
                continue;
            }
            $current = $attributes[$attributeName] ?? null;
            $isEmpty = $current === null || $current === '' || $current === 0 || $current === [];
            $raw = $context->query[$queryKey];
            if ($isEmpty && is_scalar($raw)) {
                $attributes[$attributeName] = $attribute->coerce((string) $raw);
            }
        }

        if ($ignored !== []) {
            Log::info('Storefront: ignored unknown shortcode attributes', [
                'function' => $functionKey,
                'tenant_id' => $context->tenantId,
                'attributes' => $ignored,
            ]);
        }

        $cacheable = $definition->isCacheable() && ! $context->isPreview();
        $key = $cacheable ? $this->cache->fragmentKey($context, $functionKey, $attributes, $definition->getVersion(), $definition->keySalt($context)) : null;

        if ($cacheable && $key !== null) {
            $hit = $this->cache->get($key);
            if ($hit !== null) {
                $this->cache->noteHit($context->tenantId);

                return $hit;
            }
            $this->cache->noteMiss($context->tenantId);
        }

        try {
            $fragment = $definition->renderFragment($context, $attributes);
        } catch (Throwable $e) {
            Log::error('Storefront: shortcode render failed', [
                'function' => $functionKey,
                'tenant_id' => $context->tenantId,
                'page_id' => $context->pageId,
                'error' => $e->getMessage(),
            ]);

            return $this->fail($context, "Storefront function '{$functionKey}' could not be rendered.");
        }

        if ($cacheable && $key !== null && $fragment !== '') {
            $this->cache->put($key, $fragment, $definition->cacheTtl());
        }

        return $fragment;
    }

    private function fail(Context $context, string $message): string
    {
        if ($context->isPreview()) {
            return '<div class="sf-shortcode-error" style="padding:12px;border:1px dashed #d33;color:#a00;background:#fff5f5;border-radius:6px;">'
                .htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
                .'</div>';
        }

        return '';
    }
}
