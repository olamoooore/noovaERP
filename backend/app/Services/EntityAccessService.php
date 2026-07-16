<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Checks and manages user-level access to legal entities.
 */
class EntityAccessService
{
    /**
     * Check if user can access a specific entity.
     */
    public function canAccess(int $userId, int $legalEntityId): bool
    {
        return DB::table('user_entity_access')
            ->where('user_id', $userId)
            ->where('legal_entity_id', $legalEntityId)
            ->exists();
    }

    /**
     * Check if user has a minimum role for an entity.
     */
    public function hasRole(int $userId, int $legalEntityId, string $minimumRole): bool
    {
        $hierarchy = ['viewer' => 0, 'editor' => 1, 'admin' => 2, 'owner' => 3];

        $access = DB::table('user_entity_access')
            ->where('user_id', $userId)
            ->where('legal_entity_id', $legalEntityId)
            ->first();

        if (! $access) {
            return false;
        }

        return ($hierarchy[$access->role] ?? 0) >= ($hierarchy[$minimumRole] ?? 0);
    }

    /**
     * Get all entities a user can access.
     */
    public function getAccessibleEntities(int $userId): array
    {
        return DB::table('user_entity_access as uea')
            ->join('legal_entities as le', 'le.id', '=', 'uea.legal_entity_id')
            ->where('uea.user_id', $userId)
            ->where('le.is_active', true)
            ->select('le.*', 'uea.role', 'uea.is_default')
            ->orderByDesc('uea.is_default')
            ->orderBy('le.name')
            ->get()
            ->toArray();
    }

    /**
     * Get user's default entity.
     */
    public function getDefaultEntity(int $userId): ?object
    {
        return DB::table('user_entity_access as uea')
            ->join('legal_entities as le', 'le.id', '=', 'uea.legal_entity_id')
            ->where('uea.user_id', $userId)
            ->where('uea.is_default', true)
            ->where('le.status', 'active')
            ->select('le.*', 'uea.role')
            ->first();
    }

    /**
     * Grant entity access to a user.
     */
    public function grant(int $userId, int $legalEntityId, string $role = 'viewer', bool $isDefault = false): void
    {
        if ($isDefault) {
            // Clear other defaults for this user
            DB::table('user_entity_access')
                ->where('user_id', $userId)
                ->update(['is_default' => false]);
        }

        DB::table('user_entity_access')->updateOrInsert(
            ['user_id' => $userId, 'legal_entity_id' => $legalEntityId],
            [
                'role' => $role,
                'is_default' => $isDefault,
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Revoke entity access from a user.
     */
    public function revoke(int $userId, int $legalEntityId): void
    {
        DB::table('user_entity_access')
            ->where('user_id', $userId)
            ->where('legal_entity_id', $legalEntityId)
            ->delete();
    }
}
