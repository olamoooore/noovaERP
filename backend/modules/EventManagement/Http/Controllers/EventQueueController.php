<?php

namespace Modules\EventManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\EventManagement\Services\EventQueueService;
use Modules\EventManagement\Models\QueueConfig;
use Modules\EventManagement\Models\QueueSession;

class EventQueueController extends Controller
{
    public function __construct(private EventQueueService $queueService)
    {}

    public function join(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'campaign_key' => 'required|string|max:100',
            'campaign_name' => 'nullable|string|max:255',
        ]);

        $result = $this->queueService->joinQueue(
            $validated['campaign_key'],
            $request->user()?->id,
            $request->user()?->email
        );

        $statusCode = match ($result['status']) {
            'bypass' => 200,
            'full' => 503,
            'active', 'queued' => 200,
            default => 400,
        };

        return response()->json([
            'success' => in_array($result['status'], ['active', 'queued', 'bypass']),
            'data' => $result,
        ], $statusCode);
    }

    public function status(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'campaign_key' => 'required|string|max:100',
            'queue_token' => 'required|string|max:120',
        ]);

        $status = $this->queueService->getStatus(
            $validated['campaign_key'],
            $validated['queue_token']
        );

        if (!$status) {
            return response()->json([
                'success' => false,
                'error' => 'QUEUE_TOKEN_NOT_FOUND',
                'message' => 'Queue session not found or has expired',
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
            $validated['campaign_key'],
            $validated['queue_token']
        );

        if (!$status) {
            return response()->json([
                'success' => false,
                'error' => 'QUEUE_TOKEN_NOT_FOUND',
                'message' => 'Queue session not found or has expired',
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

        $left = $this->queueService->leaveQueue(
            $validated['campaign_key'],
            $validated['queue_token']
        );

        return response()->json([
            'success' => $left,
            'message' => $left ? 'Left queue successfully' : 'Session not found',
        ]);
    }

    public function config(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'campaign_key' => 'required|string|max:100',
            'campaign_name' => 'nullable|string|max:255',
            'max_active_customers' => 'nullable|integer|min:1|max:100000',
            'slot_ttl_seconds' => 'nullable|integer|min:30|max:7200',
            'max_queue_size' => 'nullable|integer|min:1|max:1000000',
            'is_enabled' => 'nullable|boolean',
            'waiting_room_enabled' => 'nullable|boolean',
            'waiting_room_message' => 'nullable|string|max:1000',
        ]);

        $config = $this->queueService->updateConfig(
            $validated['campaign_key'],
            $validated
        );

        return response()->json([
            'success' => true,
            'data' => $config,
        ]);
    }

    public function stats(string $campaignKey): JsonResponse
    {
        $stats = $this->queueService->getQueueStats($campaignKey);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    public function checkAccess(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'campaign_key' => 'required|string|max:100',
            'queue_token' => 'required|string|max:120',
        ]);

        $canProceed = $this->queueService->canProceedToCheckout(
            $validated['campaign_key'],
            $validated['queue_token']
        );

        return response()->json([
            'success' => true,
            'data' => [
                'can_proceed' => $canProceed,
                'requires_queue' => !$canProceed,
            ],
        ]);
    }
}