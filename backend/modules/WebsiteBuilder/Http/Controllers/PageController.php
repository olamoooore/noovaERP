<?php

namespace Modules\WebsiteBuilder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\WebsiteBuilder\Models\Website;
use Modules\WebsiteBuilder\Models\Page;
use Modules\WebsiteBuilder\Http\Resources\PageResource;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class PageController extends Controller
{
    /**
     * List pages for a website.
     */
    public function index($website): JsonResponse
    {
        $website = Website::findOrFail($website);
        $pages = $website->pages()
            ->with('currentVersion')
            ->orderBy('sort_order')
            ->get();

        return response()->json(PageResource::collection($pages));
    }

    /**
     * Create a new page.
     */
    public function store(Request $request, $website): JsonResponse
    {
        $website = Website::findOrFail($website);
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:100',
            'is_home' => 'nullable|boolean',
            'seo_title' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string',
            'content' => 'nullable|array',
        ]);

        // Generate slug if not provided
        $slug = $validated['slug'] ?? \Str::slug($validated['title']);
        
        // Check if a soft-deleted page with this slug exists and restore it
        $existingPage = $website->pages()->withTrashed()->where('slug', $slug)->first();
        
        if ($existingPage) {
            if ($existingPage->trashed()) {
                // Restore the soft-deleted page
                $existingPage->restore();
                $existingPage->update([
                    'title' => $validated['title'],
                    'is_home' => $validated['is_home'] ?? false,
                    'seo_title' => $validated['seo_title'] ?? null,
                    'seo_description' => $validated['seo_description'] ?? null,
                    'status' => 'draft',
                ]);
                
                // Update content if provided
                if (!empty($validated['content'])) {
                    $existingPage->createVersion($validated['content'], TenantUserHelper::resolveTenantUserId());
                }
                
                return response()->json(new PageResource($existingPage->load('currentVersion')), 201);
            } else {
                // Page already exists and is not deleted
                return response()->json([
                    'message' => 'A page with this slug already exists.',
                    'page' => new PageResource($existingPage->load('currentVersion')),
                ], 409);
            }
        }

        // If this is set as home, unset other home pages
        if (!empty($validated['is_home'])) {
            $website->pages()->update(['is_home' => false]);
        }

        $page = $website->pages()->create([
            'title' => $validated['title'],
            'slug' => $slug,
            'is_home' => $validated['is_home'] ?? false,
            'seo_title' => $validated['seo_title'] ?? null,
            'seo_description' => $validated['seo_description'] ?? null,
            'status' => 'draft',
            'sort_order' => $website->pages()->max('sort_order') + 1,
        ]);

        // Create initial version
        if (!empty($validated['content'])) {
            $page->createVersion($validated['content'], TenantUserHelper::resolveTenantUserId());
        } else {
            $page->createVersion($this->getEmptyPageContent(), TenantUserHelper::resolveTenantUserId());
        }

        return response()->json(new PageResource($page->load('currentVersion')), 201);
    }

    /**
     * Get a single page with its current content.
     */
    public function show($page): JsonResponse
    {
        $page = Page::findOrFail($page);
        return response()->json(new PageResource($page->load(['currentVersion', 'website'])));
    }

    /**
     * Update a page and create a new version.
     */
    public function update(Request $request, $page): JsonResponse
    {
        $page = Page::findOrFail($page);
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|max:100',
            'is_home' => 'sometimes|boolean',
            'seo_title' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string',
            'status' => 'sometimes|in:draft,published',
            'sort_order' => 'sometimes|integer',
            'content' => 'sometimes|array',
        ]);

        // Handle home page toggle
        if (!empty($validated['is_home']) && !$page->is_home) {
            $page->website->pages()->update(['is_home' => false]);
        }

        $page->update(collect($validated)->except('content')->toArray());

        // Create new version if content changed
        if (isset($validated['content'])) {
            $page->createVersion($validated['content'], TenantUserHelper::resolveTenantUserId());
        }

        return response()->json(new PageResource($page->fresh(['currentVersion', 'website'])));
    }

    /**
     * Delete a page.
     */
    public function destroy($page): JsonResponse
    {
        $page = Page::findOrFail($page);
        $page->delete();

        return response()->json(['message' => 'Page deleted successfully']);
    }

    /**
     * List all versions of a page.
     */
    public function versions($page): JsonResponse
    {
        $page = Page::findOrFail($page);
        $versions = $page->versions()
            ->with('createdBy')
            ->orderByDesc('version')
            ->get();

        return response()->json($versions);
    }

    /**
     * Restore a specific version.
     */
    public function restore($page, int $version): JsonResponse
    {
        $page = Page::findOrFail($page);
        $targetVersion = $page->versions()->where('version', $version)->firstOrFail();

        $page->createVersion($targetVersion->content, TenantUserHelper::resolveTenantUserId());

        return response()->json([
            'message' => "Restored to version {$version}",
            'page' => new PageResource($page->fresh('currentVersion')),
        ]);
    }

    /**
     * Get empty page content structure.
     */
    private function getEmptyPageContent(): array
    {
        return [
            'version' => '1.0',
            'root' => [
                'type' => 'page',
                'children' => [],
            ],
            'styles' => [
                'global' => [
                    'primaryColor' => '#3b82f6',
                    'fontFamily' => 'Inter, sans-serif',
                ],
            ],
        ];
    }
}
