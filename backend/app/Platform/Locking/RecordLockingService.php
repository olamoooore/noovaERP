<?php

namespace App\Platform\Locking;

use App\Platform\Locking\Contracts\RecordLockingServiceContract;
use Illuminate\Support\Str;

class RecordLockingService implements RecordLockingServiceContract
{
    public function acquire(string $resourceType, int $resourceId, int $userId, ?int $ttlMinutes = null): object
    {
        $ttlMinutes = $ttlMinutes ?? 60;

        $existing = LockRecord::where('tenant_id', tenant()->id)
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId)
            ->whereNull('released_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($existing && $existing->user_id !== $userId) {
            return (object) [
                'acquired' => false,
                'locked_by' => $existing->user_id,
                'expires_at' => $existing->expires_at,
            ];
        }

        if ($existing && $existing->user_id === $userId) {
            $existing->update([
                'expires_at' => now()->addMinutes($ttlMinutes),
            ]);

            return (object) [
                'acquired' => true,
                'lock_id' => $existing->id,
                'expires_at' => $existing->fresh()->expires_at,
            ];
        }

        $lock = LockRecord::create([
            'id' => Str::uuid(),
            'tenant_id' => tenant()->id,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'user_id' => $userId,
            'acquired_at' => now(),
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);

        return (object) [
            'acquired' => true,
            'lock_id' => $lock->id,
            'expires_at' => $lock->expires_at,
        ];
    }

    public function release(string $resourceType, int $resourceId, int $userId): bool
    {
        $lock = LockRecord::where('tenant_id', tenant()->id)
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId)
            ->where('user_id', $userId)
            ->whereNull('released_at')
            ->first();

        if (! $lock) {
            return false;
        }

        $lock->update(['released_at' => now()]);

        return true;
    }

    public function isLocked(string $resourceType, int $resourceId): bool
    {
        return LockRecord::where('tenant_id', tenant()->id)
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId)
            ->whereNull('released_at')
            ->where('expires_at', '>', now())
            ->exists();
    }

    public function forceRelease(string $resourceType, int $resourceId): bool
    {
        $count = LockRecord::where('tenant_id', tenant()->id)
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId)
            ->whereNull('released_at')
            ->update(['released_at' => now()]);

        return $count > 0;
    }
}
