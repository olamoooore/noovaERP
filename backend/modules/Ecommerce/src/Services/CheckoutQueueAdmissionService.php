<?php

namespace Modules\Ecommerce\Services;

use Illuminate\Support\Facades\Cache;

class CheckoutQueueAdmissionService
{
    private const DEFAULT_MAX_ACTIVE = 200;

    private const DEFAULT_SLOT_TTL_SECONDS = 600;

    private const WAITING_TOKEN_TTL_SECONDS = 3600;

    public function join(string $campaignKey, int $maxActive = self::DEFAULT_MAX_ACTIVE, int $slotTtlSeconds = self::DEFAULT_SLOT_TTL_SECONDS): array
    {
        $tenantId = $this->resolveTenantId();
        $campaignKey = $this->normalizeCampaignKey($campaignKey);

        $maxActive = max(1, $maxActive);
        $slotTtlSeconds = max(30, $slotTtlSeconds);

        $token = (string) \Illuminate\Support\Str::uuid();
        $sequence = (int) Cache::increment($this->nextSequenceKey($tenantId, $campaignKey));

        $activeCount = (int) Cache::get($this->activeCountKey($tenantId, $campaignKey), 0);

        if ($activeCount < $maxActive) {
            Cache::increment($this->activeCountKey($tenantId, $campaignKey));

            $state = [
                'tenant_id' => $tenantId,
                'campaign_key' => $campaignKey,
                'token' => $token,
                'sequence' => $sequence,
                'status' => 'active',
                'max_active' => $maxActive,
                'slot_ttl_seconds' => $slotTtlSeconds,
                'joined_at' => now()->toISOString(),
                'expires_at' => now()->addSeconds($slotTtlSeconds)->toISOString(),
            ];

            Cache::put($this->tokenStateKey($tenantId, $campaignKey, $token), $state, $slotTtlSeconds);

            return [
                'status' => 'active',
                'token' => $token,
                'queue_position' => 0,
                'max_active' => $maxActive,
                'expires_at' => $state['expires_at'],
            ];
        }

        $state = [
            'tenant_id' => $tenantId,
            'campaign_key' => $campaignKey,
            'token' => $token,
            'sequence' => $sequence,
            'status' => 'queued',
            'max_active' => $maxActive,
            'slot_ttl_seconds' => $slotTtlSeconds,
            'joined_at' => now()->toISOString(),
        ];

        Cache::put($this->tokenStateKey($tenantId, $campaignKey, $token), $state, self::WAITING_TOKEN_TTL_SECONDS);
        Cache::put($this->waitingSequenceKey($tenantId, $campaignKey, $sequence), $token, self::WAITING_TOKEN_TTL_SECONDS);

        return [
            'status' => 'queued',
            'token' => $token,
            'queue_position' => $this->estimateQueuePosition($tenantId, $campaignKey, $sequence),
            'max_active' => $maxActive,
            'poll_interval_seconds' => 5,
        ];
    }

    public function getStatus(string $campaignKey, string $token): ?array
    {
        $tenantId = $this->resolveTenantId();
        $campaignKey = $this->normalizeCampaignKey($campaignKey);

        $state = Cache::get($this->tokenStateKey($tenantId, $campaignKey, $token));

        if (! $state) {
            return null;
        }

        if (($state['status'] ?? null) === 'queued') {
            return [
                'status' => 'queued',
                'token' => $token,
                'queue_position' => $this->estimateQueuePosition($tenantId, $campaignKey, (int) ($state['sequence'] ?? 0)),
                'poll_interval_seconds' => 5,
            ];
        }

        return [
            'status' => 'active',
            'token' => $token,
            'expires_at' => $state['expires_at'] ?? null,
            'queue_position' => 0,
        ];
    }

    public function heartbeat(string $campaignKey, string $token): ?array
    {
        $tenantId = $this->resolveTenantId();
        $campaignKey = $this->normalizeCampaignKey($campaignKey);
        $stateKey = $this->tokenStateKey($tenantId, $campaignKey, $token);
        $state = Cache::get($stateKey);

        if (! $state) {
            return null;
        }

        if (($state['status'] ?? null) === 'active') {
            $ttl = (int) ($state['slot_ttl_seconds'] ?? self::DEFAULT_SLOT_TTL_SECONDS);
            $state['expires_at'] = now()->addSeconds($ttl)->toISOString();
            Cache::put($stateKey, $state, $ttl);
        }

        return $this->getStatus($campaignKey, $token);
    }

    public function leave(string $campaignKey, string $token): void
    {
        $tenantId = $this->resolveTenantId();
        $campaignKey = $this->normalizeCampaignKey($campaignKey);
        $stateKey = $this->tokenStateKey($tenantId, $campaignKey, $token);
        $state = Cache::get($stateKey);

        if (! $state) {
            return;
        }

        Cache::forget($stateKey);

        if (($state['status'] ?? null) === 'active') {
            $activeCountKey = $this->activeCountKey($tenantId, $campaignKey);
            $nextActiveCount = max(0, (int) Cache::decrement($activeCountKey));
            if ($nextActiveCount < 0) {
                Cache::put($activeCountKey, 0, self::WAITING_TOKEN_TTL_SECONDS);
            }
            $this->promoteNextQueued($tenantId, $campaignKey);
        }
    }

    public function canProceedToCheckout(string $campaignKey, string $token): bool
    {
        $status = $this->getStatus($campaignKey, $token);

        return $status !== null && ($status['status'] ?? null) === 'active';
    }

    private function promoteNextQueued(string $tenantId, string $campaignKey): void
    {
        $nextPromoteKey = $this->nextPromoteSequenceKey($tenantId, $campaignKey);
        $sequence = (int) Cache::get($nextPromoteKey, 1);

        for ($i = 0; $i < 500; $i++) {
            $waitingKey = $this->waitingSequenceKey($tenantId, $campaignKey, $sequence);
            $token = Cache::get($waitingKey);

            if (! $token) {
                $sequence++;

                continue;
            }

            $stateKey = $this->tokenStateKey($tenantId, $campaignKey, (string) $token);
            $state = Cache::get($stateKey);

            if (! $state || ($state['status'] ?? null) !== 'queued') {
                Cache::forget($waitingKey);
                $sequence++;

                continue;
            }

            Cache::forget($waitingKey);
            Cache::increment($this->activeCountKey($tenantId, $campaignKey));

            $ttl = (int) ($state['slot_ttl_seconds'] ?? self::DEFAULT_SLOT_TTL_SECONDS);
            $state['status'] = 'active';
            $state['expires_at'] = now()->addSeconds($ttl)->toISOString();
            Cache::put($stateKey, $state, $ttl);

            Cache::put($nextPromoteKey, $sequence + 1, self::WAITING_TOKEN_TTL_SECONDS);

            return;
        }

        Cache::put($nextPromoteKey, $sequence, self::WAITING_TOKEN_TTL_SECONDS);
    }

    private function estimateQueuePosition(string $tenantId, string $campaignKey, int $sequence): int
    {
        if ($sequence <= 0) {
            return 0;
        }

        $nextPromoteSequence = (int) Cache::get($this->nextPromoteSequenceKey($tenantId, $campaignKey), 1);

        return max(1, $sequence - $nextPromoteSequence + 1);
    }

    private function resolveTenantId(): string
    {
        if (tenancy()->initialized) {
            return (string) tenant()->id;
        }

        throw new \RuntimeException('Tenant context not initialized for checkout queue admission');
    }

    private function normalizeCampaignKey(string $campaignKey): string
    {
        $trimmed = trim($campaignKey);

        return $trimmed === '' ? 'default' : $trimmed;
    }

    private function tokenStateKey(string $tenantId, string $campaignKey, string $token): string
    {
        return "webshop_queue:{$tenantId}:{$campaignKey}:token:{$token}";
    }

    private function activeCountKey(string $tenantId, string $campaignKey): string
    {
        return "webshop_queue:{$tenantId}:{$campaignKey}:active_count";
    }

    private function nextSequenceKey(string $tenantId, string $campaignKey): string
    {
        return "webshop_queue:{$tenantId}:{$campaignKey}:next_sequence";
    }

    private function waitingSequenceKey(string $tenantId, string $campaignKey, int $sequence): string
    {
        return "webshop_queue:{$tenantId}:{$campaignKey}:waiting:{$sequence}";
    }

    private function nextPromoteSequenceKey(string $tenantId, string $campaignKey): string
    {
        return "webshop_queue:{$tenantId}:{$campaignKey}:next_promote_sequence";
    }
}
