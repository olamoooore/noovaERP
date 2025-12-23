<?php

namespace Modules\WebsiteBuilder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\WebsiteBuilder\Models\Asset;
use Modules\WebsiteBuilder\Models\Website;

class AssetController extends Controller
{
    /**
     * Upload an asset.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'website_id' => 'required|exists:wb_websites,id',
            'file' => 'required|file|max:10240|mimes:jpg,jpeg,png,gif,webp,svg,mp4,webm,pdf',
        ]);

        $file = $request->file('file');
        $website = Website::findOrFail($validated['website_id']);

        // Generate hash for deduplication
        $hash = hash_file('sha256', $file->getRealPath());

        // Check for existing asset with same hash
        $existing = $website->assets()->where('hash', $hash)->first();
        if ($existing) {
            return response()->json($existing, 200);
        }

        // Store file
        $uuid = (string) Str::uuid();
        $extension = $file->getClientOriginalExtension();
        $path = "website-builder/{$website->uuid}/assets/{$uuid}.{$extension}";

        Storage::put($path, file_get_contents($file->getRealPath()));

        $asset = Asset::create([
            'website_id' => $website->id,
            'uuid' => $uuid,
            'filename' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'hash' => $hash,
        ]);

        return response()->json($asset, 201);
    }

    /**
     * Get asset with signed URL.
     */
    public function show(Asset $asset): JsonResponse
    {
        $url = Storage::temporaryUrl($asset->path, now()->addHour());

        return response()->json([
            'asset' => $asset,
            'url' => $url,
        ]);
    }

    /**
     * Delete an asset.
     */
    public function destroy(Asset $asset): JsonResponse
    {
        Storage::delete($asset->path);
        $asset->delete();

        return response()->json(['message' => 'Asset deleted successfully']);
    }
}
