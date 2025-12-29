<?php

namespace Modules\WebsiteBuilder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\WebsiteBuilder\Models\Website;
use Modules\WebsiteBuilder\Models\Page;

class EditorController extends Controller
{
    public function edit(Request $request, Website $website, Page $page = null)
    {
        // If no page is specified, redirect to the home page or first page
        if (!$page) {
            $page = $website->pages()->where('is_home', true)->first() 
                ?? $website->pages()->orderBy('sort_order')->first();
                
            if ($page) {
                return redirect()->route('website-builder.editor', ['website' => $website->id, 'page' => $page->id]);
            }
        }

        return view('website-builder::editor.index', [
            'website' => $website,
            'page' => $page,
            'siteUrl' => url('/'),
            // Pass configuration for Microweber JS
            'mwConfig' => [
                'website_id' => $website->id,
                'page_id' => $page?->id,
                'api_url' => url('/api/v1/website-builder'),
                'assets_url' => url('/vendor/microweber'),
            ]
        ]);
    }
}
