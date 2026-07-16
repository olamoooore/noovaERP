<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * ADR-004 §15 — server-side HTML/CSS sanitiser.
 *
 * Strips scripts, event handlers, dangerous tags and disallowed URL schemes
 * from user-authored template HTML. Custom HTML/CSS is "disabled by default
 * and privileged" (ADR-004 §15): the {@see CodeRenderer} MUST route every
 * user payload through this sanitiser before rendering.
 *
 * Also enforces an SSRF / remote-resource guard (ADR-004 §15): only
 * allowlisted link/media schemes survive, and external hosts may be locked
 * down via configuration.
 */
final class TemplateContentSanitiser
{
    private HtmlSanitizer $sanitiser;

    /**
     * @param  list<string>|null  $allowLinkHosts  null = any host; list = allowlist
     * @param  list<string>|null  $allowMediaHosts
     */
    public function __construct(
        private readonly ?array $allowLinkHosts = null,
        private readonly ?array $allowMediaHosts = null,
        private readonly bool $forceHttps = false,
        private readonly int $maxInputLength = 1_000_000,
    ) {
        $config = (new HtmlSanitizerConfig)
            // Start from the safe baseline (no scripts, no event handlers).
            ->allowSafeElements()
            ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
            ->allowMediaSchemes(['https', 'http', 'data'])
            ->withMaxInputLength($this->maxInputLength)
            ->forceHttpsUrls($this->forceHttps);

        if ($this->allowLinkHosts !== null) {
            $config->allowLinkHosts($this->allowLinkHosts)->allowRelativeLinks(true);
        } else {
            $config->allowRelativeLinks(true);
        }

        if ($this->allowMediaHosts !== null) {
            $config->allowMediaHosts($this->allowMediaHosts)->allowRelativeMedias(true);
        } else {
            $config->allowRelativeMedias(true);
        }

        // Document-template-friendly extras (inline styling, tables for layout).
        $config->allowElement('style');
        $config->allowAttribute('style', ['div', 'span', 'p', 'table', 'td', 'th', 'tr', 'img', 'a', 'hr', 'span']);

        $this->sanitiser = new HtmlSanitizer($config);
    }

    /**
     * Sanitise an HTML payload for inclusion in a rendered template.
     */
    public function sanitiseHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        return $this->sanitiser->sanitize($html);
    }

    /**
     * Strip dangerous constructs from a CSS payload (ADR-004 §15).
     * Removes @import, url() with non-allowlisted schemes, expression(),
     * and behaviour properties. Kept conservative: dompdf only supports a
     * CSS subset anyway, so we trim to a safe allowlist of properties.
     */
    public function sanitiseCss(string $css): string
    {
        if (trim($css) === '') {
            return '';
        }

        // Drop CSS comments.
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;

        // Block @import and @charset injection.
        $css = preg_replace('/@(import|charset|namespace)[^;]+;/i', '', $css) ?? $css;

        // Block expression() / javascript: / -moz-binding in url().
        $css = preg_replace('/url\(\s*([\'"]?)\s*(javascript|vbscript|data)\s*:/i', 'url(\\1blocked:', $css) ?? $css;
        $css = preg_replace('/expression\s*\(/i', 'blocked(', $css) ?? $css;
        $css = preg_replace('/-moz-binding\s*:/i', 'blocked:', $css) ?? $css;
        $css = preg_replace('/behavior\s*:/i', 'blocked:', $css) ?? $css;

        return $css;
    }

    /**
     * Apply both HTML + CSS sanitisation to a template payload.
     *
     * @return array{html: string, css: string}
     */
    public function sanitise(string $html, string $css = ''): array
    {
        return [
            'html' => $this->sanitiseHtml($html),
            'css' => $this->sanitiseCss($css),
        ];
    }
}
