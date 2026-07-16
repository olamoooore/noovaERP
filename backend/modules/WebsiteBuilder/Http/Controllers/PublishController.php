<?php

namespace Modules\WebsiteBuilder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
use Modules\WebsiteBuilder\Jobs\PublishWebsiteJob;
use Modules\WebsiteBuilder\Models\Website;
use Modules\WebsiteBuilder\Services\PublishingService;

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

        if (app()->environment('local')) {
            // Run synchronously in dev so we don't need a queue worker
            try {
                $this->publishingService->publish(
                    $website,
                    TenantUserHelper::resolveTenantUserId()
                );
            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'Publish failed: ' . $e->getMessage(),
                    'status' => 'failed',
                ], 500);
            }
        } else {
            // Dispatch async job in production
            PublishWebsiteJob::dispatch($website, TenantUserHelper::resolveTenantUserId());
        }

        return response()->json([
            'message' => 'Website published successfully.',
            'status' => 'published',
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
        PublishWebsiteJob::dispatch($website, TenantUserHelper::resolveTenantUserId(), $version);

        return response()->json([
            'message' => "Rolling back to version {$version}",
            'status' => 'pending',
        ]);
    }
}
