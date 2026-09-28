<?php

namespace Modules\Storefront\Shortcodes;

/**
 * Render tier (Appendix B §B3.4) — the cache-correctness axis.
 *
 *  CACHEABLE    (Tier A) full server HTML, shared fragment cache, anonymous-safe data only.
 *  PERSONALIZED (Tier B) HTML shell + hydration markers, NEVER cached (cart/account/… data).
 */
enum Tier: string
{
    case CACHEABLE = 'A';
    case PERSONALIZED = 'B';
}
