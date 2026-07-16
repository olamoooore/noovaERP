<?php

namespace Modules\WebsiteBuilder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\WebsiteBuilder\Models\Page;
use Modules\WebsiteBuilder\Models\Website;

class PublicSiteController extends Controller
{
    /**
     * Get a page by website slug and optional page slug.
     * If no page slug provided, returns the home page.
     */
    public function getPage(string $websiteSlug, ?string $pageSlug = null): JsonResponse
    {
        Log::info('PublicSiteController::getPage', [
            'websiteSlug' => $websiteSlug,
            'pageSlug' => $pageSlug,
        ]);

        try {
            // Find website by slug
            $website = Website::where('slug', $websiteSlug)->first();

            if (! $website) {
                Log::warning('Website not found', ['slug' => $websiteSlug]);

                return response()->json([
                    'error' => 'Website not found',
                    'message' => "No website found with slug: {$websiteSlug}",
                ], 404);
            }

            Log::info('Website found', ['id' => $website->id, 'name' => $website->name]);

            // If no page slug, get home page
            if ($pageSlug === null || $pageSlug === '') {
                $page = $website->pages()
                    ->where('is_home', true)
                    ->with('currentVersion')
                    ->first();

                // If no home page, get the first page
                if (! $page) {
                    Log::info('No home page found, getting first page');
                    $page = $website->pages()
                        ->orderBy('sort_order')
                        ->with('currentVersion')
                        ->first();
                }
            } else {
                $page = $website->pages()
                    ->where('slug', $pageSlug)
                    ->with('currentVersion')
                    ->first();
            }

            if (! $page) {
                Log::warning('Page not found', [
                    'websiteSlug' => $websiteSlug,
                    'pageSlug' => $pageSlug,
                ]);

                return response()->json([
                    'error' => 'Page not found',
                    'message' => $pageSlug
                        ? "No page found with slug: {$pageSlug}"
                        : 'No home page or pages found for this website',
                ], 404);
            }

            Log::info('Page found', ['id' => $page->id, 'title' => $page->title]);

            // Get content from current version or return empty content
            $content = $page->currentVersion?->content ?? [
                'html' => '',
                'css' => '',
                'components' => [],
                'styles' => [],
            ];

            return response()->json([
                'id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'is_home' => $page->is_home,
                'seo_title' => $page->seo_title,
                'seo_description' => $page->seo_description,
                'content' => $content,
                'website' => [
                    'id' => $website->id,
                    'name' => $website->name,
                    'slug' => $website->slug,
                    'settings' => $website->settings,
                ],
            ]);
        } catch (\Exception $e) {
            Log::warning('PublicSiteController::getPage failed', [
                'websiteSlug' => $websiteSlug,
                'pageSlug' => $pageSlug,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Public site unavailable',
                'message' => 'The public website is temporarily unavailable.',
            ], 503);
        }
    }
}
