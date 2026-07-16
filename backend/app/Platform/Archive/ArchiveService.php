<?php

namespace App\Platform\Archive;

use App\Platform\Archive\Contracts\ArchiveServiceContract;
use Illuminate\Support\Str;

class ArchiveService implements ArchiveServiceContract
{
    public function archive(string $entityType, int $entityId, int $userId, ?string $reason = null): object
    {
        $record = ArchiveRecord::create([
            'id' => Str::uuid(),
            'tenant_id' => tenant()->id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'archived_by' => $userId,
            'archived_at' => now(),
            'reason' => $reason,
            'retention_days' => 2555,
        ]);

        return (object) $record->toArray();
    }

    public function restore($archiveId, int $userId, ?string $reason = null): object
    {
        $record = ArchiveRecord::where('tenant_id', tenant()->id)
            ->findOrFail((string) $archiveId);

        $record->update([
            'restored_by' => $userId,
            'restored_at' => now(),
            'restore_reason' => $reason,
        ]);

        return (object) $record->fresh()->toArray();
    }

    public function prune(int $retentionDays = 2555): int
    {
        $cutoffDate = now()->subDays($retentionDays);

        return ArchiveRecord::where('tenant_id', tenant()->id)
            ->where('archived_at', '<', $cutoffDate)
            ->whereNotNull('restored_at')
            ->delete();
    }

    public function getArchiveHistory(string $entityType, int $entityId): array
    {
        return ArchiveRecord::where('tenant_id', tenant()->id)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->orderBy('archived_at', 'desc')
            ->get()
            ->toArray();
    }

    public function isArchived(string $entityType, int $entityId): bool
    {
        return ArchiveRecord::where('tenant_id', tenant()->id)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereNull('restored_at')
            ->exists();
    }
}
