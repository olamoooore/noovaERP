<?php

namespace App\Platform\Locking\Contracts;

use App\Platform\Locking\LockRecord;

interface RecordLockingServiceContract
{
    public function acquire(string $resourceType, int $resourceId, int $userId, ?int $ttlMinutes = null): object;

    public function release(string $resourceType, int $resourceId, int $userId): bool;

    public function isLocked(string $resourceType, int $resourceId): bool;

    public function forceRelease(string $resourceType, int $resourceId): bool;

    /**
     * Issue #896: the unexpired soft lock currently held on a record, or null
     * when nobody is editing it. Used to explain a 409 ("being edited by …").
     */
    public function activeLock(string $resourceType, int $resourceId): ?LockRecord;
}
