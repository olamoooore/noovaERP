<?php

namespace Modules\WebsiteBuilder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\WebsiteBuilder\Models\Website;
use Modules\WebsiteBuilder\Models\Page;
use Modules\WebsiteBuilder\Http\Resources\PageResource;

class PageController extends Controller
{
    /**
     * List pages for a website.
     */
    public function index(Website $website): JsonResponse
    {
        $pages = $website->pages()
            ->with('currentVersion')
            ->orderBy('sort_order')
            ->get();

        return response()->json(PageResource::collection($pages));
    }

    /**
     * Create a new page.
     */
    public function store(Request $request, Website $website): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:100',
            'is_home' => 'nullable|boolean',
            'seo_title' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string',
            'content' => 'nullable|array',
        ]);

        // If this is set as home, unset other home pages
        if (!empty($validated['is_home'])) {
            $website->pages()->update(['is_home' => false]);
        }

        $page = $website->pages()->create([
            'title' => $validated['title'],
            'slug' => $validated['slug'] ?? null,
            'is_home' => $validated['is_home'] ?? false,
            'seo_title' => $validated['seo_title'] ?? null,
            'seo_description' => $validated['seo_description'] ?? null,
            'status' => 'draft',
            'sort_order' => $website->pages()->max('sort_order') + 1,
        ]);

        // Create initial version
        if (!empty($validated['content'])) {
            $page->createVersion($validated['content'], auth()->id());
        } else {
            $page->createVersion($this->getEmptyPageContent(), auth()->id());
        }

        return response()->json(new PageResource($page->load('currentVersion')), 201);
    }

    /**
     * Get a single page with its current content.
     */
    public function show(Page $page): JsonResponse
    {
        return response()->json(new PageResource($page->load(['currentVersion', 'website'])));
    }

    /**
     * Update a page and create a new version.
     */
    public function update(Request $request, Page $page): JsonResponse
    {
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
            $page->createVersion($validated['content'], auth()->id());
        }

        return response()->json(new PageResource($page->fresh(['currentVersion', 'website'])));
    }

    /**
     * Delete a page.
     */
    public function destroy(Page $page): JsonResponse
    {
        $page->delete();

        return response()->json(['message' => 'Page deleted successfully']);
    }

    /**
     * List all versions of a page.
     */
    public function versions(Page $page): JsonResponse
    {
        $versions = $page->versions()
            ->with('createdBy')
            ->orderByDesc('version')
            ->get();

        return response()->json($versions);
    }

    /**
     * Restore a specific version.
     */
    public function restore(Page $page, int $version): JsonResponse
    {
        $targetVersion = $page->versions()->where('version', $version)->firstOrFail();

        $page->createVersion($targetVersion->content, auth()->id());

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
