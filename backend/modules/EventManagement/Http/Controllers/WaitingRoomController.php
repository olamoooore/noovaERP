<?php

namespace Modules\EventManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\EventManagement\Services\WaitingRoomService;

class WaitingRoomController extends Controller
{
    public function __construct(private WaitingRoomService $waitingRoomService)
    {}

    public function getGames(Request $request): JsonResponse
    {
        $games = $this->waitingRoomService->getAvailableGames();

        return response()->json([
            'success' => true,
            'data' => $games,
        ]);
    }

    public function startSession(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'campaign_key' => 'required|string|max:100',
            'queue_token' => 'required|string|max:120',
            'game_key' => 'required|string|max:100',
        ]);

        $result = $this->waitingRoomService->startGameSession(
            $validated['campaign_key'],
            $validated['queue_token'],
            $validated['game_key'],
            $request->user()?->id,
            $request->user()?->email
        );

        if (!$result) {
            return response()->json([
                'success' => false,
                'error' => 'INVALID_SESSION',
                'message' => 'Invalid queue session or game',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function play(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'required|integer',
            'play_data' => 'nullable|array',
        ]);

        $result = $this->waitingRoomService->playGame(
            (int) $validated['session_id'],
            $validated['play_data'] ?? []
        );

        return response()->json([
            'success' => $result['success'],
            'data' => $result,
        ]);
    }

    public function getFlashSales(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'campaign_key' => 'required|string|max:100',
        ]);

        $flashSales = $this->waitingRoomService->getActiveFlashSales($validated['campaign_key']);

        return response()->json([
            'success' => true,
            'data' => $flashSales,
        ]);
    }

    public function getMyRewards(Request $request): JsonResponse
    {
        $customerId = $request->user()?->id;
        if (!$customerId) {
            return response()->json([
                'success' => false,
                'error' => 'UNAUTHORIZED',
            ], 401);
        }

        $rewards = $this->waitingRoomService->getCustomerRewards($customerId);

        return response()->json([
            'success' => true,
            'data' => $rewards,
        ]);
    }

    public function redeemReward(Request $request): JsonResponse
    {
        $customerId = $request->user()?->id;
        if (!$customerId) {
            return response()->json([
                'success' => false,
                'error' => 'UNAUTHORIZED',
            ], 401);
        }

        $validated = $request->validate([
            'customer_reward_id' => 'required|integer',
        ]);

        $result = $this->waitingRoomService->redeemReward($validated['customer_reward_id']);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'] ?? null,
            'data' => $result,
        ], $result['success'] ? 200 : 400);
    }
}