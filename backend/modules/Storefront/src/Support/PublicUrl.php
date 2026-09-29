<?php

namespace Modules\Storefront\Support;

/**
 * Single home for merchant-facing URL sanitization (§B3.5: attributes and
 * payment return URLs are DATA — scheme-allowlisted, never code).
 * Consolidates what were three private copies (DefaultFunctions,
 * CartFunctions, CheckoutService) — SOP §4 shared-service-first.
 */
final class PublicUrl
{
    public static function sanitize(?string $url): ?string
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
