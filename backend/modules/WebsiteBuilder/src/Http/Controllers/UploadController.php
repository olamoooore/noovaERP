<?php

namespace Modules\WebsiteBuilder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\WebsiteBuilder\Jobs\ProcessUploadJob;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    /**
     * Upload a ZIP or HTML file.
     */
    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|max:51200|mimes:zip,html,htm',
            'website_name' => 'required|string|max:255',
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());

        // Store temporarily
        $uuid = (string) Str::uuid();
        $tempPath = "website-builder/uploads/{$uuid}.{$extension}";
        Storage::put($tempPath, file_get_contents($file->getRealPath()));

        // Dispatch processing job
        ProcessUploadJob::dispatch(
            $tempPath,
            $validated['website_name'],
            auth()->id(),
            $extension
        );

        return response()->json([
            'message' => 'Upload received. Processing in background.',
            'job_id' => $uuid,
        ], 202);
    }
}
