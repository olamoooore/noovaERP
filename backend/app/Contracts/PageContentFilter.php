<?php

namespace App\Contracts;

/**
 * Platform extension point: transforms page content HTML server-side during
 * the WebsiteBuilder public render pipeline (/w/{slug}).
 *
 * Implementations are registered in the container and tagged
 * 'page_content_filters'; the renderer applies every tagged filter in tag
 * registration order. This keeps WebsiteBuilder free of module-specific
 * imports while guaranteeing a SINGLE render path (SOP: no parallel
 * renderers). Failure policy is the implementation's responsibility — a
 * filter must never fatalt the page.
 *
 * Context keys (best-effort, may be absent):
 *   website_slug  string   published website slug
 *   page_id       int|null
 *   page_version_id int|null
 *   locale        string
 *   mode          string   'public'
 */
interface PageContentFilter
{
    public function filterHtml(string $html, array $context): string;
}
