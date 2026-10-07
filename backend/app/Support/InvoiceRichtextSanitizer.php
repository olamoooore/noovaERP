<?php

declare(strict_types=1);

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * #1004: allowlist sanitizer for line-description rich text.
 *
 * The invoice description/section/note can be rich text (font family, size,
 * colour, bold, italic, underline, alignment, lists). Only a tiny, safe subset
 * is kept — everything else (scripts, event handlers, links, images, unknown
 * attributes) is removed, so the sanitized HTML is safe for both the SPA
 * (dangerouslySetInnerHTML) and the PDF renderer.
 */
final class InvoiceRichtextSanitizer
{
    /** Tags whose content is removed entirely (not unwrapped). */
    private const DROP_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math'];

    /** Tags kept as-is. Everything else is unwrapped (children preserved). */
    private const ALLOWED_TAGS = ['b', 'strong', 'i', 'em', 'u', 's', 'strike', 'br', 'span', 'p', 'div', 'ul', 'ol', 'li'];

    /** Inline style properties allowed on span/p/div/li. */
    private const ALLOWED_STYLES = ['color', 'font-weight', 'font-style', 'text-decoration', 'font-family', 'font-size', 'text-align'];

    /** Fonts the PDF engine can render deterministically (see #1004 decision). */
    private const ALLOWED_FONT_FAMILIES = [
        'arial', 'helvetica', 'verdana', 'tahoma', 'trebuchet ms', 'georgia',
        'garamond', 'times new roman', 'courier new', 'serif', 'sans-serif', 'monospace',
    ];

    /** Font-size range accepted in px (mirrors the editor dropdown). */
    private const FONT_SIZE_PATTERN = '/^(?:[6-9]|[1-6]\d|7[0-2])px$/';

    private const TEXT_ALIGNS = ['left', 'center', 'right', 'justify'];

    public static function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="richtext-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();

        $root = null;
        foreach ($document->getElementsByTagName('div') as $div) {
            if ($div->getAttribute('id') === 'richtext-root') {
                $root = $div;
                break;
            }
        }

        if (! $root instanceof DOMElement) {
            return null;
        }

        self::sanitizeChildren($root);

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        $output = trim($output);

        return $output !== '' ? $output : null;
    }

    /** Plain-text copy for search/exports/e-invoice. */
    public static function plainText(?string $html): ?string
    {
        $clean = self::clean($html);
        if ($clean === null) {
            return null;
        }

        $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], ' ', $clean)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return $text !== '' ? $text : null;
    }

    private static function sanitizeChildren(DOMNode $node): void
    {
        for ($child = $node->firstChild; $child !== null;) {
            $next = $child->nextSibling;

            if ($child instanceof DOMText) {
                $child = $next;

                continue;
            }

            if (! $child instanceof DOMElement) {
                $node->removeChild($child);
                $child = $next;

                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROP_CONTENT, true)) {
                $node->removeChild($child);
                $child = $next;

                continue;
            }

            self::sanitizeAttributes($child);

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                // Unwrap unknown tags but keep their (sanitized) content.
                self::sanitizeChildren($child);
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                $child = $next;

                continue;
            }

            self::sanitizeChildren($child);
            $child = $next;
        }
    }

    private static function sanitizeAttributes(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            $value = (string) $attribute->value;

            if ($name === 'style') {
                $style = self::sanitizeStyle($value);
                if ($style === '') {
                    $element->removeAttribute($attribute->name);
                } else {
                    $element->setAttribute('style', $style);
                }

                continue;
            }

            $element->removeAttribute($attribute->name);
        }
    }

    private static function sanitizeStyle(string $style): string
    {
        $kept = [];

        foreach (explode(';', $style) as $declaration) {
            if (! str_contains($declaration, ':')) {
                continue;
            }

            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            $property = strtolower($property);

            if (! in_array($property, self::ALLOWED_STYLES, true)) {
                continue;
            }

            $lower = strtolower($value);

            if ($property === 'color') {
                if (preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $value) === 1) {
                    $kept[] = 'color:'.$value;
                }

                continue;
            }

            if ($property === 'font-family') {
                $family = strtolower(trim($value, " \t\"'"));
                if (in_array($family, self::ALLOWED_FONT_FAMILIES, true)) {
                    $kept[] = 'font-family:'.$family;
                }

                continue;
            }

            if ($property === 'font-size') {
                if (preg_match(self::FONT_SIZE_PATTERN, $lower) === 1) {
                    $kept[] = 'font-size:'.$lower;
                }

                continue;
            }

            if ($property === 'text-align') {
                if (in_array($lower, self::TEXT_ALIGNS, true)) {
                    $kept[] = 'text-align:'.$lower;
                }

                continue;
            }

            if (in_array($lower, ['bold', 'bolder', 'normal', '700', 'italic', 'underline', 'line-through'], true)) {
                $kept[] = $property.':'.$lower;
            }
        }

        return implode(';', $kept);
    }
}
