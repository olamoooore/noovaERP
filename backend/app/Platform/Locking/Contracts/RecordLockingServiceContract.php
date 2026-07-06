<?php

namespace App\Platform\Locking\Contracts;

interface RecordLockingServiceContract
{
    public function acquire(string $resourceType, int $resourceId, int $userId, ?int $ttlMinutes = null): object;
    public function release(string $resourceType, int $resourceId, int $userId): bool;
    public function isLocked(string $resourceType, int $resourceId): bool;
    public function forceRelease(string $resourceType, int $resourceId): bool;
}
