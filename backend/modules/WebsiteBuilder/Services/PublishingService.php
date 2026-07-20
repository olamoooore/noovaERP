<?php

namespace Modules\WebsiteBuilder\Services;

use Illuminate\Support\Facades\Storage;
use Modules\WebsiteBuilder\Models\PublishLog;
use Modules\WebsiteBuilder\Models\Website;

class PublishingService
{
    /**
     * Publish a website to static HTML.
     */
    public function publish(Website $website, int $userId, ?int $rollbackVersion = null): PublishLog
    {
        $latestVersion = $website->publishLogs()->max('version') ?? 0;
        $newVersion = $latestVersion + 1;

        $log = PublishLog::create([
            'website_id' => $website->id,
            'version' => $newVersion,
            'status' => 'pending',
            'published_by' => $userId,
        ]);

        try {
            // Update website status first — the public route serves from the
            // database (currentVersion), not static files, so this is what matters.
            $website->update([
                'status' => 'published',
                'published_at' => now(),
            ]);

            // Mark all page versions as published AND update page status
            foreach ($website->pages as $page) {
                if ($page->currentVersion) {
                    $page->currentVersion->update(['is_published' => true]);
                }
                $page->update(['status' => 'published']);
            }

            // Generate static files (optional — failure here should not
            // prevent the publish from succeeding, since the public route
            // reads directly from the database).
            try {
                $outputPath = $this->generateStaticSite($website, $newVersion, $rollbackVersion);
            } catch (\Exception $staticEx) {
                \Log::warning('PublishingService: static site generation failed (non-fatal)', [
                    'website_id' => $website->id,
                    'error' => $staticEx->getMessage(),
                ]);
                $outputPath = null;
            }

            $log->markSuccess($outputPath);

            return $log;
        } catch (\Exception $e) {
            $log->markFailed($e->getMessage());
            throw $e;
        }
    }

    /**
     * Generate static HTML files from the website.
     */
    private function generateStaticSite(Website $website, int $version, ?int $rollbackVersion = null): string
    {
        $outputDir = "website-builder/published/{$website->uuid}/v{$version}";

        // If rollback, copy from previous version
        if ($rollbackVersion !== null) {
            $sourceDir = "website-builder/published/{$website->uuid}/v{$rollbackVersion}";
            if (Storage::exists($sourceDir)) {
                $files = Storage::allFiles($sourceDir);
                foreach ($files as $file) {
                    $newPath = str_replace("v{$rollbackVersion}", "v{$version}", $file);
                    Storage::copy($file, $newPath);
                }

                return $outputDir;
            }
        }

        // Generate HTML for each page
        foreach ($website->pages()->with('currentVersion')->get() as $page) {
            $content = $page->currentVersion?->content ?? [];
            $html = $this->renderPageToHtml($page, $content, $website->settings ?? []);

            $filename = $page->is_home ? 'index.html' : "{$page->slug}.html";
            Storage::put("{$outputDir}/{$filename}", $html);
        }

        return $outputDir;
    }

    /**
     * Render a page's JSON content to HTML.
     */
    private function renderPageToHtml($page, array $content, array $siteSettings): string
    {
        $root = $content['root'] ?? ['type' => 'page', 'children' => []];
        $styles = array_merge($siteSettings, $content['styles']['global'] ?? []);

        $bodyHtml = $this->renderNode($root);

        $primaryColor = $styles['primaryColor'] ?? '#3b82f6';
        $fontFamily = $styles['fontFamily'] ?? 'Inter, sans-serif';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$page->seo_title} </title>
    <meta name="description" content="{$page->seo_description}">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: {$primaryColor};
            --font-family: {$fontFamily};
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: var(--font-family); line-height: 1.6; }
        a { color: var(--primary-color); }
        .btn-primary { 
            background: var(--primary-color); 
            color: white; 
            padding: 12px 24px; 
            border-radius: 8px; 
            text-decoration: none;
            display: inline-block;
        }
    </style>
</head>
<body>
{$bodyHtml}
</body>
</html>
HTML;
    }

    /**
     * Recursively render a node to HTML.
     */
    private function renderNode(array $node): string
    {
        $type = $node['type'] ?? 'div';
        $props = $node['props'] ?? [];
        $children = $node['children'] ?? [];

        $childrenHtml = '';
        foreach ($children as $child) {
            $childrenHtml .= $this->renderNode($child);
        }

        return match ($type) {
            'page' => $childrenHtml,
            'section' => "<section style=\"{$this->propsToStyle($props)}\">{$childrenHtml}</section>",
            'container' => '<div style="max-width: '.($props['maxWidth'] ?? '1200px')."; margin: 0 auto; padding: 0 20px;\">{$childrenHtml}</div>",
            'text' => "<div style=\"{$this->propsToStyle($props)}\">".($props['content'] ?? '').'</div>',
            'image' => '<img src="'.($props['src'] ?? '').'" alt="'.($props['alt'] ?? '')."\" style=\"{$this->propsToStyle($props)}\" />",
            'button' => '<a href="'.($props['href'] ?? '#').'" class="btn-'.($props['variant'] ?? 'primary').'">'.($props['text'] ?? 'Button').'</a>',
            default => "<div style=\"{$this->propsToStyle($props)}\">{$childrenHtml}</div>",
        };
    }

    /**
     * Convert props to inline CSS.
     */
    private function propsToStyle(array $props): string
    {
        $styles = [];

        if (! empty($props['backgroundColor'])) {
            $styles[] = "background-color: {$props['backgroundColor']}";
        }
        if (! empty($props['padding'])) {
            $p = $props['padding'];
            $styles[] = 'padding: '.($p['top'] ?? 0).'px '.($p['right'] ?? 0).'px '.($p['bottom'] ?? 0).'px '.($p['left'] ?? 0).'px';
        }
        if (! empty($props['fontSize'])) {
            $styles[] = "font-size: {$props['fontSize']}px";
        }
        if (! empty($props['textAlign'])) {
            $styles[] = "text-align: {$props['textAlign']}";
        }

        return implode('; ', $styles);
    }
}
