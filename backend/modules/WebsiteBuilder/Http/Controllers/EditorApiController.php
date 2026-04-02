<?php

namespace Modules\WebsiteBuilder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\WebsiteBuilder\Models\Page;
use Modules\WebsiteBuilder\Models\PageVersion;

class EditorApiController extends Controller
{
    public function saveContent(Request $request)
    {
        $validated = $request->validate([
            'page_id' => 'required|exists:wb_pages,id',
            'html' => 'required|string',
            // Microweber might send other fields, capturing 'em
            'css' => 'nullable|string',
            'json' => 'nullable|array',
            'header_js' => 'nullable|string',
            'body_js' => 'nullable|string',
        ]);

        $page = Page::findOrFail($validated['page_id']);
        
        // Create new version
        $version = PageVersion::create([
            'page_id' => $page->id,
            'version' => $page->versions()->max('version') + 1,
            'content' => [
                'html' => $validated['html'],
                'css' => $request->input('css'),
                'json' => $request->input('json'),
                'header_js' => $request->input('header_js'),
                'body_js' => $request->input('body_js'),
            ],
            'is_current' => true,
            'created_by' => Auth::id(),
        ]);

        // Unset previous current
        $page->versions()->where('id', '!=', $version->id)->update(['is_current' => false]);

        return response()->json(['success' => true, 'version' => $version->version]);
    }

    public function getContent(Request $request, $page)
    {
        $page = Page::findOrFail($page);
        // Get current version
        $version = $page->currentVersion;

        if (!$version) {
            return response()->json(['html' => '', 'css' => '', 'json' => null]);
        }

        return response()->json($version->content);
    }

    public function upload(Request $request)
    {
        // Microweber sends 'file' in mulitpart form data
        // We reuse AssetController logic or call it directly
        // Ideally we resolve the website_id from the page or session
        
        // For MVP, if page_id is passed, we get website_id
        $websiteId = null;
        if ($request->has('page_id')) {
            $page = Page::find($request->page_id);
            if ($page) $websiteId = $page->website_id;
        }

        if (!$websiteId && $request->has('website_id')) {
             $websiteId = $request->website_id;
        }

        if (!$websiteId) {
            return response()->json(['error' => 'Website ID required'], 400);
        }

        $request->merge(['website_id' => $websiteId]);

        return app(AssetController::class)->store($request);
    }
}
