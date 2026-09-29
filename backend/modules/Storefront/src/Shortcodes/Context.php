<?php

namespace Modules\Storefront\Shortcodes;

use Modules\Storefront\Models\StorefrontSettings;

/**
 * Per-request render context (Appendix B §B3.2/§B3.5 rule 1): the tenant is
 * bound HERE, from the server-side tenancy context — never from an attribute.
 */
final class Context
{
    public const MODE_PUBLIC = 'public';   // published /w/ page render

    public const MODE_PREVIEW = 'preview'; // editor live preview (errors visible, no shared cache)

    public function __construct(
        public readonly string $tenantId,
        public readonly string $mode = self::MODE_PUBLIC,
        public readonly ?int $pageId = null,
        public readonly ?int $pageVersionId = null,
        public readonly string $websiteSlug = '',
        public readonly string $locale = 'en',
        public readonly ?StorefrontSettings $settings = null,
        /** The visitor's GET query params (e.g. ?product=, ?sfcat=, ?sfq=) —
         *  data for registry-bounded lookups only, never an attribute source
         *  that widens scope (§B3.5 rule 1: tenant still never comes from here). */
        public readonly array $query = [],
    ) {}

    public function isPreview(): bool
    {
        return $this->mode === self::MODE_PREVIEW;
    }

    public function settings(): StorefrontSettings
    {
        return $this->settings ?? StorefrontSettings::current();
    }
}
