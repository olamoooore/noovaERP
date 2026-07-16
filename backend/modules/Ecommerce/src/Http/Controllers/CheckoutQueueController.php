<?php

namespace Modules\Ecommerce\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ecommerce\Services\CheckoutQueueAdmissionService;

class CheckoutQueueController extends Controller
{
    public function __construct(private CheckoutQueueAdmissionService $queueService) {}

    public function join(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'campaign_key' => 'required|string|max:100',
            'max_active' => 'nullable|integer|min:1|max:100000',
            'slot_ttl_seconds' => 'nullable|integer|min:30|max:7200',
        ]);

        $result = $this->queueService->join(
            (string) $validated['campaign_key'],
            (int) ($validated['max_active'] ?? 200),
            (int) ($validated['slot_ttl_seconds'] ?? 600),
        );

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'campaign_key' => 'required|string|max:100',
            'queue_token' => 'required|string|max:120',
        ]);

        $status = $this->queueService->getStatus(
            (string) $validated['campaign_key'],
            (string) $validated['queue_token'],
        );

        if (! $status) {
            return response()->json([
                'success' => false,
                'error' => 'QUEUE_TOKEN_NOT_FOUND',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $status,
        ]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'campaign_key' => 'required|string|max:100',
            'queue_token' => 'required|string|max:120',
        ]);

        $status = $this->queueService->heartbeat(
            (string) $validated['campaign_key'],
            (string) $validated['queue_token'],
        );

        if (! $status) {
            return response()->json([
                'success' => false,
                'error' => 'QUEUE_TOKEN_NOT_FOUND',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $status,
        ]);
    }

    public function leave(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'campaign_key' => 'required|string|max:100',
            'queue_token' => 'required|string|max:120',
        ]);

        $this->queueService->leave(
            (string) $validated['campaign_key'],
            (string) $validated['queue_token'],
        );

        return response()->json([
            'success' => true,
            'message' => 'Left queue successfully',
        ]);
    }
}
