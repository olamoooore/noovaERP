<?php

namespace App\Platform\Approvals\Contracts;

interface ApprovalServiceContract
{
    public function createRequest(string $type, string $subjectType, int $subjectId, float $amount, string $reason, ?string $notes = null): object;

    public function approve(int|string $requestId, int $userId, ?string $notes = null): object;

    public function reject(int|string $requestId, int $userId, string $reason): object;

    public function escalate(int|string $requestId): object;

    public function delegate(int $fromUserId, int $toUserId, ?string $expiresAt = null): void;

    public function removeDelegation(int $fromUserId): void;

    public function expireOld(): int;

    public function getPending(?string $type = null, ?int $userId = null): array;

    public function checkPolicy(string $type, float $amount, int $userId): array;
}
