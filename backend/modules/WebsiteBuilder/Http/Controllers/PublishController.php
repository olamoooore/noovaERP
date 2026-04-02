<?php

namespace Modules\WebsiteBuilder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\WebsiteBuilder\Models\Website;
use Modules\WebsiteBuilder\Services\PublishingService;
use Modules\WebsiteBuilder\Jobs\PublishWebsiteJob;

class PublishController extends Controller
{
    public function __construct(
        private PublishingService $publishingService
    ) {}

    /**
     * Publish a website.
     */
    public function publish($website): JsonResponse
    {
        $website = Website::findOrFail($website);
        // Dispatch async job
        PublishWebsiteJob::dispatch($website, auth()->id());

        return response()->json([
            'message' => 'Publishing started. You will be notified when complete.',
            'status' => 'pending',
        ]);
    }

    /**
     * Rollback to a previous version.
     */
    public function rollback($website, int $version): JsonResponse
    {
        $website = Website::findOrFail($website);
        $log = $website->publishLogs()
            ->where('version', $version)
            ->where('status', 'success')
            ->firstOrFail();

        // Dispatch publish job with rollback flag
        PublishWebsiteJob::dispatch($website, auth()->id(), $version);

        return response()->json([
            'message' => "Rolling back to version {$version}",
            'status' => 'pending',
        ]);
    }
}
