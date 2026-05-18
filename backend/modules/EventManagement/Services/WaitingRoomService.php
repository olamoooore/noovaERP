<?php

namespace Modules\EventManagement\Services;

use Modules\EventManagement\Models\WaitingRoomGame;
use Modules\EventManagement\Models\WaitingRoomGameSession;
use Modules\EventManagement\Models\GameReward;
use Modules\EventManagement\Models\CustomerGameReward;
use Modules\EventManagement\Models\FlashSale;
use Modules\EventManagement\Models\QueueSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WaitingRoomService
{
    public function getAvailableGames(): array
    {
        $games = WaitingRoomGame::active()->get();
        return $games->map(fn($game) => [
            'game_key' => $game->game_key,
            'name' => $game->name,
            'game_type' => $game->game_type,
            'description' => $game->description,
            'play_duration_seconds' => $game->play_duration_seconds,
            'max_plays_per_session' => $game->max_plays_per_session,
        ])->toArray();
    }

    public function startGameSession(string $campaignKey, string $queueToken, string $gameKey, ?int $customerId = null, ?string $customerEmail = null): ?array
    {
        $queueSession = QueueSession::where('token', $queueToken)
            ->where('campaign_key', $campaignKey)
            ->first();

        if (!$queueSession) {
            return null;
        }

        $game = WaitingRoomGame::where('game_key', $gameKey)->active()->first();
        if (!$game) {
            return null;
        }

        $existingSession = WaitingRoomGameSession::where('queue_session_id', $queueSession->id)
            ->where('waiting_room_game_id', $game->id)
            ->whereNull('completed_at')
            ->first();

        if ($existingSession) {
            if (!$existingSession->canPlay()) {
                return [
                    'session_id' => $existingSession->id,
                    'can_play' => false,
                    'message' => 'No plays remaining in this session',
                ];
            }
            return [
                'session_id' => $existingSession->id,
                'game' => $game,
                'can_play' => true,
            ];
        }

        $session = WaitingRoomGameSession::create([
            'queue_session_id' => $queueSession->id,
            'waiting_room_game_id' => $game->id,
            'customer_id' => $customerId,
            'customer_email' => $customerEmail,
        ]);

        return [
            'session_id' => $session->id,
            'game' => $game,
            'can_play' => true,
        ];
    }

    public function playGame(int $sessionId, array $playData): array
    {
        $session = WaitingRoomGameSession::with('game')->find($sessionId);
        if (!$session || !$session->canPlay()) {
            return ['success' => false, 'message' => 'Cannot play'];
        }

        $game = $session->game;
        $result = $this->processGameResult($game, $playData);
        $score = $result['score'] ?? 0;

        $session->recordPlay($result, $score);

        $rewards = [];
        if (isset($result['reward_key'])) {
            $rewardResult = $this->awardReward($session, $result['reward_key']);
            if ($rewardResult['success']) {
                $rewards[] = $rewardResult['reward'];
            }
        }

        $nextPlay = $session->canPlay();
        $sessionComplete = !$nextPlay;

        return [
            'success' => true,
            'result' => $result,
            'score' => $score,
            'total_score' => $session->total_score,
            'play_count' => $session->play_count,
            'rewards' => $rewards,
            'can_play_again' => $nextPlay,
            'session_complete' => $sessionComplete,
        ];
    }

    public function getActiveFlashSales(string $campaignKey): array
    {
        $flashSales = FlashSale::currentlyRunning()
            ->showInWaitingRoom()
            ->get();

        return $flashSales->map(fn($sale) => [
            'id' => $sale->id,
            'name' => $sale->name,
            'description' => $sale->description,
            'image_url' => $sale->image_url,
            'discount_display' => $sale->discount_display,
            'quantity_remaining' => $sale->quantity_remaining,
            'ends_at' => $sale->ends_at?->toISOString(),
        ])->toArray();
    }

    public function getCustomerRewards(int $customerId): array
    {
        $rewards = CustomerGameReward::where('customer_id', $customerId)
            ->where('status', CustomerGameReward::STATUS_PENDING)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', now());
            })
            ->with('reward')
            ->get();

        return $rewards->map(fn($cr) => [
            'id' => $cr->id,
            'reward_type' => $cr->reward_type,
            'coin_amount' => $cr->coin_amount,
            'voucher_code' => $cr->voucher_code,
            'voucher_amount' => $cr->voucher_amount,
            'expires_at' => $cr->expires_at?->toISOString(),
            'reward' => $cr->reward ? [
                'name' => $cr->reward->name,
                'description' => $cr->reward->description,
            ] : null,
        ])->toArray();
    }

    public function redeemReward(int $customerRewardId): array
    {
        $customerReward = CustomerGameReward::with('reward')->find($customerRewardId);
        if (!$customerReward) {
            return ['success' => false, 'message' => 'Reward not found'];
        }

        if (!$customerReward->isPending()) {
            return ['success' => false, 'message' => 'Reward already used or expired'];
        }

        if ($customerReward->isExpired()) {
            $customerReward->markAsExpired();
            return ['success' => false, 'message' => 'Reward has expired'];
        }

        if ($customerReward->reward_type === GameReward::TYPE_VOUCHER) {
            if ($customerReward->reward && !$customerReward->reward->use()) {
                return ['success' => false, 'message' => 'Voucher exhausted'];
            }
        }

        $customerReward->markAsRedeemed();
        return [
            'success' => true,
            'message' => 'Reward redeemed successfully',
            'voucher_code' => $customerReward->voucher_code,
        ];
    }

    private function processGameResult(WaitingRoomGame $game, array $playData): array
    {
        $config = $game->game_config;
        $rewardConfig = $game->reward_config;

        $baseResult = [
            'score' => 0,
            'outcome' => 'lose',
            'reward_key' => null,
        ];

        $roll = mt_rand(1, 100);
        $winChance = $rewardConfig['win_chance'] ?? 30;

        if ($roll <= $winChance) {
            $baseResult['outcome'] = 'win';
            $baseResult['score'] = $config['base_score'] ?? 100;

            $tiers = $rewardConfig['tiers'] ?? [];
            if (!empty($tiers)) {
                $selectedTier = $this->selectRewardTier($tiers);
                $baseResult['reward_key'] = $selectedTier['reward_key'];
                $baseResult['score'] = $selectedTier['score'] ?? $baseResult['score'];
            }
        }

        return $baseResult;
    }

    private function selectRewardTier(array $tiers): array
    {
        $totalWeight = array_sum(array_column($tiers, 'weight'));
        $roll = mt_rand(1, $totalWeight);

        $cumulative = 0;
        foreach ($tiers as $tier) {
            $cumulative += $tier['weight'];
            if ($roll <= $cumulative) {
                return $tier;
            }
        }

        return $tiers[0];
    }

    private function awardReward(WaitingRoomGameSession $session, string $rewardKey): array
    {
        $reward = GameReward::where('reward_key', $rewardKey)->first();
        if (!$reward) {
            return ['success' => false, 'message' => 'Reward not found'];
        }

        $customerReward = CustomerGameReward::create([
            'customer_id' => $session->customer_id,
            'waiting_room_game_session_id' => $session->id,
            'game_reward_id' => $reward->id,
            'reward_type' => $reward->reward_type,
            'coin_amount' => $reward->coin_value,
            'voucher_amount' => $reward->voucher_value,
            'voucher_code' => $reward->reward_type === GameReward::TYPE_VOUCHER ? GameReward::generateVoucherCode() : null,
            'expires_at' => $reward->voucher_expires_at ?? now()->addMonths(1),
        ]);

        return [
            'success' => true,
            'reward' => [
                'id' => $customerReward->id,
                'type' => $reward->reward_type,
                'coin_amount' => $reward->coin_value,
                'voucher_code' => $customerReward->voucher_code,
                'name' => $reward->name,
            ],
        ];
    }
}