<?php

declare(strict_types=1);

namespace Modules\Storefront\Http\Controllers\Editor;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
use Modules\Storefront\Support\StarterPageTemplates;
use Modules\WebsiteBuilder\Models\Page;
use Modules\WebsiteBuilder\Models\Website;

/**
 * POST /website-builder/storefront/pages/starter (Appendix E §E4.3, P5).
 *
 * The "Insert store pages" action: creates the recommended starter shop
 * pages on the tenant's website as ordinary builder pages containing
 * storefront functions. Idempotent by slug — existing pages are SKIPPED,
 * never overwritten. New pages land as drafts (never published): they are
 * content proposals the merchant reviews and publishes through the normal
 * builder pipeline (P5 publish truthfulness — see PublishingService).
 */
class StarterPagesController extends Controller
{
    public function store(): JsonResponse
    {
        // One storefront per tenant: the builder's first website is the
        // shop's home. No site yet ⇒ create one in the builder first
        // (honest 422 rather than silently inventing a website).
        $website = Website::orderBy('id')->first();

        if (! $website) {
            return response()->json([
                'message' => 'Create a website in the Website Builder before inserting store pages.',
                'code' => 'no_website',
            ], 422);
        }

        $userId = TenantUserHelper::resolveTenantUserId();
        $created = [];
        $skipped = [];

        foreach (StarterPageTemplates::pages($website->slug) as $template) {
            if (Page::where('website_id', $website->id)->where('slug', $template['slug'])->exists()) {
                $skipped[] = $template['slug'];

                continue;
            }

            $page = Page::create([
                'website_id' => $website->id,
                'title' => $template['title'],
                'slug' => $template['slug'],
                'is_home' => false,
                'status' => 'draft',
                'sort_order' => 100,
            ]);

            $page->createVersion([
                'html' => $template['html'],
                'css' => '',
                'header_js' => '',
                'body_js' => '',
            ], $userId);

            $created[] = $template['slug'];
        }

        return response()->json([
            'message' => $created === []
                ? 'All starter store pages already exist — nothing was changed.'
                : 'Inserted '.count($created).' starter store page(s).',
            'data' => [
                'website' => ['id' => $website->id, 'slug' => $website->slug],
                'created' => $created,
                'skipped' => $skipped,
            ],
        ], $created === [] ? 200 : 201);
    }
}
