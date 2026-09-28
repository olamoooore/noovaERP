<?php

namespace Modules\Storefront\Support;

/**
 * Runtime bundle version (Appendix B §B5 "Versioning"): bump ONLY on
 * breaking island-contract changes; published pages pin the version that
 * was current at render time via data-sf-runtime. Old bundles stay served
 * (immutable asset dirs) for at least one release.
 */
final class StorefrontRuntime
{
    public const VERSION = 'v1';

    public static function assetUrl(string $file): string
    {
        return '/storefront/assets/'.self::VERSION.'/'.$file;
    }
}
