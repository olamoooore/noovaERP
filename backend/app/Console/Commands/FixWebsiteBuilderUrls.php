<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixWebsiteBuilderUrls extends Command
{
    protected $signature = 'website-builder:fix-urls';
    protected $description = 'Fix /sites/ URLs and broken image paths in website builder page content';

    public function handle(): int
    {
        $versions = DB::table('wb_page_versions')
            ->where(function ($q) {
                $q->where('content', 'LIKE', '%/sites/%')
                  ->orWhere('content', 'LIKE', '%noovaerp-logo.png%');
            })
            ->get();

        if ($versions->isEmpty()) {
            $this->info('No page versions with broken URLs found.');
            return self::SUCCESS;
        }

        $this->info("Found {$versions->count()} page version(s) to fix.");

        $fixed = 0;
        foreach ($versions as $version) {
            $content = is_string($version->content) ? json_decode($version->content, true) : $version->content;
            if (!is_array($content)) continue;

            $changed = false;

            // Fix html field
            if (!empty($content['html'])) {
                $original = $content['html'];

                // Rewrite /sites/{slug} → /w/{slug} in href attributes
                $content['html'] = preg_replace('#(/sites/)([a-zA-Z0-9_-]+)#', '/w/$2', $content['html']);

                // Rewrite absolute domain URLs: https://domain.com/sites/slug → /w/slug
                $content['html'] = preg_replace('#https?://[^/\'"\s]+/sites/([a-zA-Z0-9_-]+)#', '/w/$1', $content['html']);

                // Fix relative noovaerp-logo.png path
                $content['html'] = str_replace('noovaerp-logo.png', '/storage/noovaerp-logo.png', $content['html']);

                if ($content['html'] !== $original) {
                    $changed = true;
                }
            }

            // Fix header_js field
            if (!empty($content['header_js'])) {
                $original = $content['header_js'];
                $content['header_js'] = str_replace('noovaerp-logo.png', '/storage/noovaerp-logo.png', $content['header_js']);
                if ($content['header_js'] !== $original) {
                    $changed = true;
                }
            }

            // Fix body_js field
            if (!empty($content['body_js'])) {
                $original = $content['body_js'];
                $content['body_js'] = str_replace('noovaerp-logo.png', '/storage/noovaerp-logo.png', $content['body_js']);
                if ($content['body_js'] !== $original) {
                    $changed = true;
                }
            }

            if ($changed) {
                DB::table('wb_page_versions')
                    ->where('id', $version->id)
                    ->update(['content' => $content]);
                $fixed++;
                $this->line("  Fixed version ID {$version->id} (page_id: {$version->page_id})");
            }
        }

        $this->info("Done. Fixed {$fixed} page version(s).");
        return self::SUCCESS;
    }
}
