<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sales\Services\SalesActionQueueService;

/**
 * Issue #365 [SALES-DASH]: read-only action queues for the Sales cockpit.
 *
 * GET /sales/dashboard/action-queues?mine=1&limit=5
 */
class SalesActionQueueController extends Controller
{
    public function __construct(private readonly SalesActionQueueService $queues) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mine' => 'nullable|boolean',
            'limit' => 'nullable|integer|min:1|max:'.SalesActionQueueService::MAX_LIMIT,
        ]);

        return response()->json([
            'data' => $this->queues->summary(
                $request,
                (bool) ($validated['mine'] ?? false),
                (int) ($validated['limit'] ?? SalesActionQueueService::DEFAULT_LIMIT),
            ),
        ]);
    }
}
