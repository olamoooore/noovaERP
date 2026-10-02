<?php

namespace Modules\WebsiteBuilder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
use Modules\WebsiteBuilder\Http\Resources\WebsiteResource;
use Modules\WebsiteBuilder\Models\Website;
use App\Models\Hostname;
use App\PublicSites\WebsitePublicationService;

class WebsiteController extends Controller
{
    public function __construct(private readonly WebsitePublicationService $publications) {}

    /**
     * List all websites for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Website::with(['createdBy'])
            ->orderByDesc('updated_at');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('search')) {
            $query->where('name', 'like', '%'.$request->search.'%');
        }

        $perPage = $request->input('per_page', 20);
        $websites = $query->paginate($perPage);

        return response()->json($websites);
    }

    /**
     * Create a new website.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:100|unique:wb_websites,slug',
            'settings' => 'nullable|array',
            'favicon' => 'nullable|string|max:500',
        ]);

        $validated['created_by'] = TenantUserHelper::resolveTenantUserId();

        $website = Website::create($validated);
        $this->publications->sync($website);

        return response()->json(new WebsiteResource($website->load('createdBy')), 201);
    }

    /**
     * Get a single website with pages.
     */
    public function show($website): JsonResponse
    {
        $website = Website::findOrFail($website);

        return response()->json(
            new WebsiteResource($website->load(['pages', 'createdBy']))
        );
    }

    /**
     * Update a website.
     */
    public function update(Request $request, $website): JsonResponse
    {
        $website = Website::findOrFail($website);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|max:100|unique:wb_websites,slug,'.$website->id,
            'status' => 'sometimes|in:draft,published,archived',
            'settings' => 'sometimes|array',
            'favicon' => 'nullable|string|max:500',
        ]);

        $website->update($validated);
        $this->publications->sync($website->fresh());

        return response()->json(new WebsiteResource($website->fresh(['pages', 'createdBy'])));
    }

    /**
     * Delete a website (soft delete).
     */
    public function destroy($website): JsonResponse
    {
        $website = Website::findOrFail($website);
        $website->delete();
        $this->publications->sync($website, 'deleted');
        Hostname::query()
            ->where('tenant_id', (string) tenant('id'))
            ->where('website_uuid', $website->uuid)
            ->where('purpose', 'website')
            ->whereIn('serving_status', ['pending', 'active', 'suspended'])
            ->update([
                'serving_status' => 'suspended',
                'primary_effective' => false,
                'failure_code' => 'website_deleted',
                'failure_message' => 'The linked website was deleted.',
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Website deleted successfully']);
    }
}
