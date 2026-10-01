<?php

namespace Modules\WebsiteBuilder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
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

        // Always run synchronously so the UI reflects the true state.
        // Async dispatch caused silent failures when the queue worker was down.
        try {
            $log = $this->publishingService->publish(
                $website,
                TenantUserHelper::resolveTenantUserId()
            );
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Publish failed: '.$e->getMessage(),
                'status' => 'failed',
            ], 500);
        }

        return response()->json([
            'message' => 'Website published successfully.',
            'status' => 'published',
            'version' => $log->version,
        ]);
    }

    /**
     * Rollback to a previous version. Synchronous and truthful (P5): the
     * old flow copied static files in a queue job while the public route
     * serves from the database — visitors never saw a "rollback". Restoring
     * now re-publishes the snapshot's page versions through the same path
     * as a normal publish.
     */
    public function rollback($website, int $version): JsonResponse
    {
        $website = Website::findOrFail($website);

        try {
            $log = $this->publishingService->rollback(
                $website,
                $version,
                TenantUserHelper::resolveTenantUserId()
            );
        } catch (\RuntimeException $e) {
            $status = in_array((int) $e->getCode(), [404, 422], true) ? (int) $e->getCode() : 422;

            return response()->json([
                'message' => 'Rollback failed: '.$e->getMessage(),
                'status' => 'failed',
            ], $status);
        }

        return response()->json([
            'message' => "Rolled back to the content of version {$version}.",
            'status' => 'published',
            'version' => $log->version,
            'rollback_of' => $version,
        ]);
    }
}
