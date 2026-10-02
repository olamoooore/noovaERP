<?php

namespace Modules\WebsiteBuilder\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\WebsiteBuilder\Models\Page;
use Modules\WebsiteBuilder\Models\PageVersion;
use Modules\WebsiteBuilder\Models\PublishLog;
use Modules\WebsiteBuilder\Models\Website;

class PublishingService
{
    public function __construct(private readonly \App\PublicSites\WebsitePublicationService $publications) {}

    /**
     * Publish a website: every page's CURRENT version becomes THE published
     * version (older published flags are demoted, so exactly one version per
     * page is live), and the publish log records the page→version snapshot
     * that rollback needs to be truthful.
     */
    public function publish(Website $website, int $userId): PublishLog
    {
        return $this->finalize($website, $userId, null);
    }

    /**
     * Honest rollback (P5): the old flow only re-copied static files while
     * the public route serves from the database — visitors never saw the
     * "rolled back" site. This restores each snapshot page's recorded
     * version as a NEW current version (forward restore, like a git revert:
     * history is append-only and drafts in between remain recoverable),
     * then publishes synchronously through the same finalize() path as a
     * normal publish. The new log row carries rollback_of provenance.
     *
     * @throws \RuntimeException when the target snapshot is unusable
     */
    public function rollback(Website $website, int $fromVersion, int $userId): PublishLog
    {
        $source = $website->publishLogs()
            ->where('version', $fromVersion)
            ->where('status', 'success')
            ->first();

        if (! $source) {
            throw new \RuntimeException("No successful publish log for version {$fromVersion}.", 404);
        }

        $snapshot = $source->page_versions ?? [];

        if ($snapshot === []) {
            // Logs written BEFORE the snapshot columns existed cannot be
            // restored truthfully (the old rollback was a file-copy no-op).
            // Refuse loudly instead of pretending.
            throw new \RuntimeException(
                'That publish predates version snapshots and cannot be rolled back to. Publish the site again to establish a restorable baseline.',
                422
            );
        }

        foreach ($snapshot as $pageId => $versionId) {
            $page = Page::find($pageId);

            if (! $page) {
                continue; // page deleted after that publish — nothing to restore; remaining pages still roll back
            }

            $version = PageVersion::find($versionId);

            if (! $version) {
                throw new \RuntimeException("Snapshot version {$versionId} for page {$pageId} no longer exists.", 422);
            }

            $page->createVersion($version->content ?? [], $userId);
        }

        return $this->finalize($website, $userId, $fromVersion);
    }

    /**
     * Shared publish finalization (also used by rollback). Synchronous by
     * design — async publish/rollback silently did nothing when the queue
     * worker was down (lesson recorded in PublishController).
     */
    private function finalize(Website $website, int $userId, ?int $rollbackOf): PublishLog
    {
        $newVersion = ($website->publishLogs()->max('version') ?? 0) + 1;

        $log = PublishLog::create([
            'website_id' => $website->id,
            'version' => $newVersion,
            'status' => 'pending',
            'rollback_of' => $rollbackOf,
            'published_by' => $userId,
        ]);

        try {
            // Update the tenant website and its published page-version
            // snapshot atomically. The public route reads this database state,
            // not the optional static files generated below.
            $snapshot = DB::transaction(function () use ($website) {
                $website->update([
                    'status' => 'published',
                    'published_at' => now(),
                ]);

                $map = [];

                foreach ($website->pages as $page) {
                    $current = $page->currentVersion;

                    if ($current) {
                        // Exactly one published version per page: demote all,
                        // then promote the current one.
                        PageVersion::where('page_id', $page->id)->update(['is_published' => false]);
                        $current->update(['is_published' => true]);
                        $map[$page->id] = $current->id;
                    }

                    $page->update(['status' => 'published']);
                }

                return $map;
            });

            $log->update(['page_versions' => $snapshot]);
            $this->publications->sync($website->fresh());

            // Generate static files (optional — failure here should not
            // prevent the publish from succeeding, since the public route
            // reads directly from the database).
            try {
                $outputPath = $this->generateStaticSite($website, $newVersion);
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
    private function generateStaticSite(Website $website, int $version): string
    {
        $outputDir = "website-builder/published/{$website->uuid}/v{$version}";

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
