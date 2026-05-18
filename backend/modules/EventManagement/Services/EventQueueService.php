<?php

namespace Modules\EventManagement\Services;

use Modules\EventManagement\Models\QueueConfig;
use Modules\EventManagement\Models\QueueSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EventQueueService
{
    public function getOrCreateConfig(string $campaignKey, ?string $campaignName = null): QueueConfig
    {
        return QueueConfig::firstOrCreate(
            ['campaign_key' => $campaignKey],
            [
                'campaign_name' => $campaignName ?? $campaignKey,
                'max_active_customers' => 200,
                'slot_ttl_seconds' => 600,
                'max_queue_size' => 10000,
                'is_enabled' => true,
                'waiting_room_enabled' => true,
            ]
        );
    }

    public function joinQueue(
        string $campaignKey,
        ?int $customerId = null,
        ?string $customerEmail = null
    ): array {
        $config = $this->getOrCreateConfig($campaignKey);

        if (!$config->is_enabled) {
            return [
                'status' => 'bypass',
                'token' => null,
                'queue_position' => 0,
                'message' => 'Queue is currently disabled for this campaign',
            ];
        }

        if ($config->isQueueFull()) {
            return [
                'status' => 'full',
                'token' => null,
                'queue_position' => 0,
                'message' => 'Queue is full. Please try again later.',
            ];
        }

        if ($config->hasCapacity()) {
            $token = (string) Str::uuid();
            $sequence = $this->getNextSequence($campaignKey);

            $session = QueueSession::create([
                'token' => $token,
                'campaign_key' => $campaignKey,
                'customer_id' => $customerId,
                'customer_email' => $customerEmail,
                'sequence' => $sequence,
                'status' => QueueSession::STATUS_ACTIVE,
                'joined_at' => now(),
                'activated_at' => now(),
                'expires_at' => now()->addSeconds($config->slot_ttl_seconds),
            ]);

            return [
                'status' => 'active',
                'token' => $token,
                'queue_position' => 0,
                'expires_at' => $session->expires_at->toISOString(),
                'max_active' => $config->max_active_customers,
                'config' => $config,
            ];
        }

        $token = (string) Str::uuid();
        $sequence = $this->getNextSequence($campaignKey);

        $session = QueueSession::create([
            'token' => $token,
            'campaign_key' => $campaignKey,
            'customer_id' => $customerId,
            'customer_email' => $customerEmail,
            'sequence' => $sequence,
            'status' => QueueSession::STATUS_QUEUED,
            'joined_at' => now(),
        ]);

        return [
            'status' => 'queued',
            'token' => $token,
            'queue_position' => $session->queue_position,
            'max_active' => $config->max_active_customers,
            'poll_interval_seconds' => 5,
            'config' => $config,
        ];
    }

    public function getStatus(string $campaignKey, string $token): ?array
    {
        $session = QueueSession::where('token', $token)
            ->where('campaign_key', $campaignKey)
            ->first();

        if (!$session) {
            return null;
        }

        $config = QueueConfig::where('campaign_key', $campaignKey)->first();

        if ($session->status === QueueSession::STATUS_QUEUED) {
            if ($session->isExpired()) {
                $session->markAsExpired();
                return [
                    'status' => 'expired',
                    'token' => $token,
                    'queue_position' => 0,
                ];
            }

            return [
                'status' => 'queued',
                'token' => $token,
                'queue_position' => $session->queue_position,
                'estimated_wait_seconds' => $this->estimateWaitTime($session, $config),
                'poll_interval_seconds' => 5,
            ];
        }

        if ($session->status === QueueSession::STATUS_ACTIVE) {
            if ($session->isExpired()) {
                $session->markAsExpired();
                $this->promoteNextQueued($campaignKey);
                return [
                    'status' => 'expired',
                    'token' => $token,
                    'queue_position' => 0,
                ];
            }

            return [
                'status' => 'active',
                'token' => $token,
                'expires_at' => $session->expires_at?->toISOString(),
                'queue_position' => 0,
            ];
        }

        return [
            'status' => $session->status,
            'token' => $token,
            'queue_position' => 0,
        ];
    }

    public function heartbeat(string $campaignKey, string $token): ?array
    {
        $session = QueueSession::where('token', $token)
            ->where('campaign_key', $campaignKey)
            ->first();

        if (!$session) {
            return null;
        }

        if ($session->status === QueueSession::STATUS_ACTIVE) {
            $session->incrementHeartbeat();
        }

        return $this->getStatus($campaignKey, $token);
    }

    public function leaveQueue(string $campaignKey, string $token): bool
    {
        $session = QueueSession::where('token', $token)
            ->where('campaign_key', $campaignKey)
            ->first();

        if (!$session) {
            return false;
        }

        $wasActive = $session->status === QueueSession::STATUS_ACTIVE;
        $session->markAsCompleted();

        if ($wasActive) {
            $this->promoteNextQueued($campaignKey);
        }

        return true;
    }

    public function canProceedToCheckout(string $campaignKey, string $token): bool
    {
        $session = QueueSession::where('token', $token)
            ->where('campaign_key', $campaignKey)
            ->first();

        return $session && $session->status === QueueSession::STATUS_ACTIVE && !$session->isExpired();
    }

    public function updateConfig(
        string $campaignKey,
        array $data
    ): ?QueueConfig {
        $config = $this->getOrCreateConfig($campaignKey);
        $config->update(array_filter([
            'campaign_name' => $data['campaign_name'] ?? null,
            'max_active_customers' => $data['max_active_customers'] ?? null,
            'slot_ttl_seconds' => $data['slot_ttl_seconds'] ?? null,
            'max_queue_size' => $data['max_queue_size'] ?? null,
            'is_enabled' => $data['is_enabled'] ?? null,
            'waiting_room_enabled' => $data['waiting_room_enabled'] ?? null,
            'waiting_room_message' => $data['waiting_room_message'] ?? null,
            'settings' => $data['settings'] ?? null,
        ]));
        return $config->fresh();
    }

    public function getQueueStats(string $campaignKey): array
    {
        $config = QueueConfig::where('campaign_key', $campaignKey)->first();
        if (!$config) {
            return [
                'campaign_key' => $campaignKey,
                'is_enabled' => false,
                'active_count' => 0,
                'queued_count' => 0,
                'max_active' => 0,
            ];
        }

        return [
            'campaign_key' => $campaignKey,
            'campaign_name' => $config->campaign_name,
            'is_enabled' => $config->is_enabled,
            'active_count' => $config->current_active_count,
            'queued_count' => $config->current_queue_size,
            'max_active' => $config->max_active_customers,
            'max_queue_size' => $config->max_queue_size,
            'waiting_room_enabled' => $config->waiting_room_enabled,
        ];
    }

    private function getNextSequence(string $campaignKey): int
    {
        $max = QueueSession::where('campaign_key', $campaignKey)->max('sequence');
        return ($max ?? 0) + 1;
    }

    private function promoteNextQueued(string $campaignKey): void
    {
        $config = QueueConfig::where('campaign_key', $campaignKey)->first();
        if (!$config || !$config->hasCapacity()) {
            return;
        }

        $nextSession = QueueSession::where('campaign_key', $campaignKey)
            ->where('status', QueueSession::STATUS_QUEUED)
            ->orderBy('sequence')
            ->first();

        if ($nextSession) {
            $nextSession->markAsActive($config->slot_ttl_seconds);
        }
    }

    private function estimateWaitTime(QueueSession $session, ?QueueConfig $config): int
    {
        if (!$config) {
            return 60;
        }

        $avgTransactionSeconds = $config->slot_ttl_seconds;
        $aheadInQueue = QueueSession::where('campaign_key', $session->campaign_key)
            ->where('status', QueueSession::STATUS_QUEUED)
            ->where('sequence', '<', $session->sequence)
            ->count();

        return $aheadInQueue * $avgTransactionSeconds;
    }
}