<?php

namespace Modules\WebsiteBuilder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\WebsiteBuilder\Models\Asset;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AssetController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|image|max:10240', // 10MB max, images only for now
            'website_id' => 'required|exists:wb_websites,id',
        ]);

        $file = $request->file('file');
        $hash = hash_file('sha256', $file->path());

        // Deduplication per tenant/website? Let's check if hash exists for this website
        $existing = Asset::where('website_id', $request->website_id)
            ->where('hash', $hash)
            ->first();

        if ($existing) {
            return response()->json([
                'url' => Storage::disk('public')->url($existing->path),
                'asset' => $existing
            ]);
        }

        $path = $file->store('website-assets/' . $request->website_id, 'public');

        $asset = Asset::create([
            'website_id' => $request->website_id,
            'uuid' => Str::uuid(),
            'filename' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'hash' => $hash,
        ]);

        return response()->json([
            'url' => Storage::disk('public')->url($asset->path),
            'asset' => $asset
        ], 201);
    }
}
