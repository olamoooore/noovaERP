<?php

namespace App\Platform\Archive\Contracts;

interface ArchiveServiceContract
{
    public function archive(string $entityType, int $entityId, int $userId, ?string $reason = null): object;

    public function restore($archiveId, int $userId, ?string $reason = null): object;

    public function prune(int $retentionDays = 2555): int;

    public function getArchiveHistory(string $entityType, int $entityId): array;

    public function isArchived(string $entityType, int $entityId): bool;
}
