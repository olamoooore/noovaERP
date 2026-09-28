<?php

namespace Modules\Storefront\Support;

/**
 * Allowlist richtext sanitizer (Appendix B §B3.5 rule 3).
 *
 * Only designated richtext fields (product description policy) pass through
 * this helper — everything else is escaped with Blade's {{ }}. The allowlist
 * has NO anchors, images or iframes, so no URL-scheme smuggling is possible;
 * any surviving attribute on an allowed tag is stripped before return.
 */
final class RichtextSanitizer
{
    private const ALLOWED_TAGS = '<p><br><strong><b><em><i><ul><ol><li>';

    public static function sanitize(?string $html, int $maxLength = 2000): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $cleaned = strip_tags($html, self::ALLOWED_TAGS);

        // Drop every attribute that survived on an allowed tag, and any
        // stray script-ish content (tag names are already limited by the
        // allowlist, but never trust one sanitizer layer).
        $cleaned = preg_replace('/\s+[a-zA-Z0-9_:-]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/', '', $cleaned);
        $cleaned = preg_replace('/<\/?(script|style|iframe|object|embed|form|link|meta)\b[^>]*>/i', '', $cleaned);

        return mb_substr(trim($cleaned), 0, $maxLength);
    }

    /**
     * Plain-text summary (single line, all tags stripped) for card excerpts.
     */
    public static function plain(?string $html, int $maxLength = 160): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($html)));

        return mb_substr($text, 0, $maxLength);
    }
}
