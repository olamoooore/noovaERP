<?php

namespace Modules\EventManagement\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\EventManagement\Exceptions\ArtistManagementException;
use Modules\EventManagement\Models\Artist;
use Modules\EventManagement\Models\ArtistEvent;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\Group;
use Modules\EventManagement\Models\GroupMember;

/**
 * Artist Management Service
 *
 * Handles CRUD operations for artists, groups, and their relationships.
 * Provides search, filtering, and performance history tracking capabilities
 * for K-POP artist management in multi-tenant environment.
 *
 * Requirements: 12.1, 12.2, 12.4
 */
class ArtistManagementService
{
    private AuditService $auditService;

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    /**
     * Create a new artist
     */
    public function createArtist(array $artistData): Artist
    {
        try {
            DB::beginTransaction();

            // Validate required fields
            $this->validateArtistData($artistData);

            // Create artist
            $artist = Artist::create([
                'tenant_id' => tenant('id'),
                'name' => $artistData['name'],
                'stage_name' => $artistData['stage_name'] ?? null,
                'korean_name' => $artistData['korean_name'] ?? null,
                'gender' => $artistData['gender'] ?? null,
                'birth_date' => $artistData['birth_date'] ?? null,
                'debut_date' => $artistData['debut_date'] ?? null,
                'agency' => $artistData['agency'] ?? null,
                'nationality' => $artistData['nationality'] ?? null,
                'biography' => $artistData['biography'] ?? null,
                'positions' => $artistData['positions'] ?? [],
                'profile_image_url' => $artistData['profile_image_url'] ?? null,
                'social_media_links' => $artistData['social_media_links'] ?? [],
                'official_colors' => $artistData['official_colors'] ?? [],
                'status' => $artistData['status'] ?? 'active',
                'metadata' => $artistData['metadata'] ?? [],
            ]);

            // Log audit trail
            $this->auditService->logActivity(
                'artist_created',
                Artist::class,
                $artist->id,
                ['artist_name' => $artist->name, 'stage_name' => $artist->stage_name]
            );

            DB::commit();

            Log::info('Artist created successfully', [
                'artist_id' => $artist->id,
                'name' => $artist->name,
                'tenant_id' => tenant('id'),
            ]);

            return $artist;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create artist', [
                'error' => $e->getMessage(),
                'data' => $artistData,
                'tenant_id' => tenant('id'),
            ]);
            throw new ArtistManagementException('Failed to create artist: '.$e->getMessage());
        }
    }

    /**
     * Update an existing artist
     */
    public function updateArtist(int $artistId, array $artistData): Artist
    {
        try {
            DB::beginTransaction();

            $artist = Artist::findOrFail($artistId);
            $originalData = $artist->toArray();

            // Validate update data
            $this->validateArtistData($artistData, $artistId);

            // Update artist
            $artist->update($artistData);

            // Log audit trail
            $this->auditService->logActivity(
                'artist_updated',
                Artist::class,
                $artist->id,
                [
                    'original' => $originalData,
                    'updated' => $artist->toArray(),
                    'changes' => $artist->getChanges(),
                ]
            );

            DB::commit();

            Log::info('Artist updated successfully', [
                'artist_id' => $artist->id,
                'changes' => $artist->getChanges(),
                'tenant_id' => tenant('id'),
            ]);

            return $artist->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update artist', [
                'artist_id' => $artistId,
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id'),
            ]);
            throw new ArtistManagementException('Failed to update artist: '.$e->getMessage());
        }
    }

    /**
     * Delete an artist (soft delete)
     */
    public function deleteArtist(int $artistId): bool
    {
        try {
            DB::beginTransaction();

            $artist = Artist::findOrFail($artistId);

            // Check if artist has active group memberships
            if ($artist->groupMemberships()->where('status', 'active')->exists()) {
                throw new ArtistManagementException('Cannot delete artist with active group memberships');
            }

            // Check if artist has confirmed future events
            if ($artist->artistEvents()->where('status', 'confirmed')
                ->whereHas('event', fn ($q) => $q->where('start_date', '>', now()))->exists()) {
                throw new ArtistManagementException('Cannot delete artist with confirmed future events');
            }

            $artist->delete();

            // Log audit trail
            $this->auditService->logActivity(
                'artist_deleted',
                Artist::class,
                $artist->id,
                ['artist_name' => $artist->name, 'stage_name' => $artist->stage_name]
            );

            DB::commit();

            Log::info('Artist deleted successfully', [
                'artist_id' => $artistId,
                'tenant_id' => tenant('id'),
            ]);

            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete artist', [
                'artist_id' => $artistId,
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id'),
            ]);
            throw new ArtistManagementException('Failed to delete artist: '.$e->getMessage());
        }
    }

    /**
     * Create a new group
     */
    public function createGroup(array $groupData): Group
    {
        try {
            DB::beginTransaction();

            // Validate required fields
            $this->validateGroupData($groupData);

            // Create group
            $group = Group::create([
                'tenant_id' => tenant('id'),
                'name' => $groupData['name'],
                'korean_name' => $groupData['korean_name'] ?? null,
                'english_name' => $groupData['english_name'] ?? null,
                'name_meaning' => $groupData['name_meaning'] ?? null,
                'debut_date' => $groupData['debut_date'] ?? null,
                'formation_date' => $groupData['formation_date'] ?? null,
                'agency' => $groupData['agency'] ?? null,
                'sub_labels' => $groupData['sub_labels'] ?? [],
                'description' => $groupData['description'] ?? null,
                'concept' => $groupData['concept'] ?? null,
                'genres' => $groupData['genres'] ?? [],
                'member_count' => 0, // Will be updated when members are added
                'official_colors' => $groupData['official_colors'] ?? [],
                'fandom_name' => $groupData['fandom_name'] ?? null,
                'logo_url' => $groupData['logo_url'] ?? null,
                'profile_image_url' => $groupData['profile_image_url'] ?? null,
                'social_media_links' => $groupData['social_media_links'] ?? [],
                'official_websites' => $groupData['official_websites'] ?? [],
                'status' => $groupData['status'] ?? 'active',
                'metadata' => $groupData['metadata'] ?? [],
            ]);

            // Log audit trail
            $this->auditService->logActivity(
                'group_created',
                Group::class,
                $group->id,
                ['group_name' => $group->name, 'agency' => $group->agency]
            );

            DB::commit();

            Log::info('Group created successfully', [
                'group_id' => $group->id,
                'name' => $group->name,
                'tenant_id' => tenant('id'),
            ]);

            return $group;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create group', [
                'error' => $e->getMessage(),
                'data' => $groupData,
                'tenant_id' => tenant('id'),
            ]);
            throw new ArtistManagementException('Failed to create group: '.$e->getMessage());
        }
    }

    /**
     * Update an existing group
     */
    public function updateGroup(int $groupId, array $groupData): Group
    {
        try {
            DB::beginTransaction();

            $group = Group::findOrFail($groupId);
            $originalData = $group->toArray();

            // Validate update data
            $this->validateGroupData($groupData, $groupId);

            // Update group
            $group->update($groupData);

            // Log audit trail
            $this->auditService->logActivity(
                'group_updated',
                Group::class,
                $group->id,
                [
                    'original' => $originalData,
                    'updated' => $group->toArray(),
                    'changes' => $group->getChanges(),
                ]
            );

            DB::commit();

            Log::info('Group updated successfully', [
                'group_id' => $group->id,
                'changes' => $group->getChanges(),
                'tenant_id' => tenant('id'),
            ]);

            return $group->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update group', [
                'group_id' => $groupId,
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id'),
            ]);
            throw new ArtistManagementException('Failed to update group: '.$e->getMessage());
        }
    }

    /**
     * Delete a group (soft delete)
     */
    public function deleteGroup(int $groupId): bool
    {
        try {
            DB::beginTransaction();

            $group = Group::findOrFail($groupId);

            // Check if group has active members
            if ($group->groupMemberships()->where('status', 'active')->exists()) {
                throw new ArtistManagementException('Cannot delete group with active members');
            }

            // Check if group has confirmed future events
            if ($group->artistEvents()->where('status', 'confirmed')
                ->whereHas('event', fn ($q) => $q->where('start_date', '>', now()))->exists()) {
                throw new ArtistManagementException('Cannot delete group with confirmed future events');
            }

            $group->delete();

            // Log audit trail
            $this->auditService->logActivity(
                'group_deleted',
                Group::class,
                $group->id,
                ['group_name' => $group->name, 'agency' => $group->agency]
            );

            DB::commit();

            Log::info('Group deleted successfully', [
                'group_id' => $groupId,
                'tenant_id' => tenant('id'),
            ]);

            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete group', [
                'group_id' => $groupId,
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id'),
            ]);
            throw new ArtistManagementException('Failed to delete group: '.$e->getMessage());
        }
    }

    /**
     * Add member to group
     */
    public function addGroupMember(int $groupId, int $artistId, array $memberData = []): GroupMember
    {
        try {
            DB::beginTransaction();

            $group = Group::findOrFail($groupId);
            $artist = Artist::findOrFail($artistId);

            // Check if artist is already an active member
            if ($group->groupMemberships()
                ->where('artist_id', $artistId)
                ->where('status', 'active')
                ->exists()) {
                throw new ArtistManagementException('Artist is already an active member of this group');
            }

            // Create group membership
            $groupMember = $group->addMember($artist, $memberData);

            // Log audit trail
            $this->auditService->logActivity(
                'group_member_added',
                GroupMember::class,
                $groupMember->id,
                [
                    'group_name' => $group->name,
                    'artist_name' => $artist->name,
                    'positions' => $memberData['positions'] ?? [],
                    'is_leader' => $memberData['is_leader'] ?? false,
                ]
            );

            DB::commit();

            Log::info('Group member added successfully', [
                'group_id' => $groupId,
                'artist_id' => $artistId,
                'member_id' => $groupMember->id,
                'tenant_id' => tenant('id'),
            ]);

            return $groupMember;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to add group member', [
                'group_id' => $groupId,
                'artist_id' => $artistId,
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id'),
            ]);
            throw new ArtistManagementException('Failed to add group member: '.$e->getMessage());
        }
    }

    /**
     * Remove member from group
     */
    public function removeGroupMember(int $groupId, int $artistId, ?string $reason = null): bool
    {
        try {
            DB::beginTransaction();

            $group = Group::findOrFail($groupId);
            $artist = Artist::findOrFail($artistId);

            $groupMember = $group->groupMemberships()
                ->where('artist_id', $artistId)
                ->where('status', 'active')
                ->first();

            if (! $groupMember) {
                throw new ArtistManagementException('Artist is not an active member of this group');
            }

            $group->removeMember($artist, $reason);

            // Log audit trail
            $this->auditService->logActivity(
                'group_member_removed',
                GroupMember::class,
                $groupMember->id,
                [
                    'group_name' => $group->name,
                    'artist_name' => $artist->name,
                    'reason' => $reason,
                ]
            );

            DB::commit();

            Log::info('Group member removed successfully', [
                'group_id' => $groupId,
                'artist_id' => $artistId,
                'reason' => $reason,
                'tenant_id' => tenant('id'),
            ]);

            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to remove group member', [
                'group_id' => $groupId,
                'artist_id' => $artistId,
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id'),
            ]);
            throw new ArtistManagementException('Failed to remove group member: '.$e->getMessage());
        }
    }

    /**
     * Link artist to event
     */
    public function linkArtistToEvent(int $artistId, int $eventId, string $role = 'main', array $performanceData = []): ArtistEvent
    {
        try {
            DB::beginTransaction();

            $artist = Artist::findOrFail($artistId);
            $event = Event::findOrFail($eventId);

            // Check if artist is already linked to this event
            if (ArtistEvent::where('artist_id', $artistId)
                ->where('event_id', $eventId)
                ->whereIn('status', ['confirmed', 'tentative'])
                ->exists()) {
                throw new ArtistManagementException('Artist is already linked to this event');
            }

            // Create artist-event association
            $artistEvent = ArtistEvent::create([
                'tenant_id' => tenant('id'),
                'event_id' => $eventId,
                'artist_id' => $artistId,
                'role' => $role,
                'performance_order' => $performanceData['performance_order'] ?? null,
                'performance_duration' => $performanceData['performance_duration'] ?? null,
                'setlist' => $performanceData['setlist'] ?? [],
                'billing_name' => $performanceData['billing_name'] ?? null,
                'special_notes' => $performanceData['special_notes'] ?? null,
                'performance_fee' => $performanceData['performance_fee'] ?? null,
                'technical_requirements' => $performanceData['technical_requirements'] ?? [],
                'status' => $performanceData['status'] ?? 'tentative',
                'metadata' => $performanceData['metadata'] ?? [],
            ]);

            // Log audit trail
            $this->auditService->logActivity(
                'artist_linked_to_event',
                ArtistEvent::class,
                $artistEvent->id,
                [
                    'artist_name' => $artist->name,
                    'event_title' => $event->title,
                    'role' => $role,
                ]
            );

            DB::commit();

            Log::info('Artist linked to event successfully', [
                'artist_id' => $artistId,
                'event_id' => $eventId,
                'role' => $role,
                'tenant_id' => tenant('id'),
            ]);

            return $artistEvent;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to link artist to event', [
                'artist_id' => $artistId,
                'event_id' => $eventId,
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id'),
            ]);
            throw new ArtistManagementException('Failed to link artist to event: '.$e->getMessage());
        }
    }

    /**
     * Link group to event
     */
    public function linkGroupToEvent(int $groupId, int $eventId, string $role = 'main', array $performanceData = []): ArtistEvent
    {
        try {
            DB::beginTransaction();

            $group = Group::findOrFail($groupId);
            $event = Event::findOrFail($eventId);

            // Check if group is already linked to this event
            if (ArtistEvent::where('group_id', $groupId)
                ->where('event_id', $eventId)
                ->whereIn('status', ['confirmed', 'tentative'])
                ->exists()) {
                throw new ArtistManagementException('Group is already linked to this event');
            }

            // Create group-event association
            $artistEvent = ArtistEvent::create([
                'tenant_id' => tenant('id'),
                'event_id' => $eventId,
                'group_id' => $groupId,
                'role' => $role,
                'performance_order' => $performanceData['performance_order'] ?? null,
                'performance_duration' => $performanceData['performance_duration'] ?? null,
                'setlist' => $performanceData['setlist'] ?? [],
                'billing_name' => $performanceData['billing_name'] ?? null,
                'special_notes' => $performanceData['special_notes'] ?? null,
                'performance_fee' => $performanceData['performance_fee'] ?? null,
                'technical_requirements' => $performanceData['technical_requirements'] ?? [],
                'status' => $performanceData['status'] ?? 'tentative',
                'metadata' => $performanceData['metadata'] ?? [],
            ]);

            // Log audit trail
            $this->auditService->logActivity(
                'group_linked_to_event',
                ArtistEvent::class,
                $artistEvent->id,
                [
                    'group_name' => $group->name,
                    'event_title' => $event->title,
                    'role' => $role,
                ]
            );

            DB::commit();

            Log::info('Group linked to event successfully', [
                'group_id' => $groupId,
                'event_id' => $eventId,
                'role' => $role,
                'tenant_id' => tenant('id'),
            ]);

            return $artistEvent;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to link group to event', [
                'group_id' => $groupId,
                'event_id' => $eventId,
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id'),
            ]);
            throw new ArtistManagementException('Failed to link group to event: '.$e->getMessage());
        }
    }

    /**
     * Search artists with filters
     */
    public function searchArtists(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Artist::query();

        // Apply search term
        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        // Apply status filter
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Apply agency filter
        if (! empty($filters['agency'])) {
            $query->byAgency($filters['agency']);
        }

        // Apply debut year filter
        if (! empty($filters['debut_year'])) {
            $query->byDebutYear($filters['debut_year']);
        }

        // Apply solo filter
        if (! empty($filters['solo']) && $filters['solo'] === true) {
            $query->solo();
        }

        // Apply position filter
        if (! empty($filters['position'])) {
            $query->whereJsonContains('positions', $filters['position']);
        }

        // Apply gender filter
        if (! empty($filters['gender'])) {
            $query->where('gender', $filters['gender']);
        }

        // Apply sorting
        $sortBy = $filters['sort_by'] ?? 'name';
        $sortOrder = $filters['sort_order'] ?? 'asc';
        $query->orderBy($sortBy, $sortOrder);

        return $query->paginate($perPage);
    }

    /**
     * Search groups with filters
     */
    public function searchGroups(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Group::query();

        // Apply search term
        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        // Apply status filter
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Apply agency filter
        if (! empty($filters['agency'])) {
            $query->byAgency($filters['agency']);
        }

        // Apply debut year filter
        if (! empty($filters['debut_year'])) {
            $query->byDebutYear($filters['debut_year']);
        }

        // Apply member count filter
        if (! empty($filters['min_members']) || ! empty($filters['max_members'])) {
            $query->byMemberCount($filters['min_members'] ?? null, $filters['max_members'] ?? null);
        }

        // Apply genre filter
        if (! empty($filters['genre'])) {
            $query->whereJsonContains('genres', $filters['genre']);
        }

        // Apply sorting
        $sortBy = $filters['sort_by'] ?? 'name';
        $sortOrder = $filters['sort_order'] ?? 'asc';
        $query->orderBy($sortBy, $sortOrder);

        return $query->paginate($perPage);
    }

    /**
     * Get artist performance history
     */
    public function getArtistPerformanceHistory(int $artistId): Collection
    {
        $artist = Artist::findOrFail($artistId);

        return $artist->getPerformanceHistory();
    }

    /**
     * Get group performance history
     */
    public function getGroupPerformanceHistory(int $groupId): Collection
    {
        $group = Group::findOrFail($groupId);

        return $group->getPerformanceHistory();
    }

    /**
     * Get artist performance statistics
     */
    public function getArtistPerformanceStats(int $artistId): array
    {
        $artist = Artist::findOrFail($artistId);

        return $artist->getPerformanceStats();
    }

    /**
     * Get group performance statistics
     */
    public function getGroupPerformanceStats(int $groupId): array
    {
        $group = Group::findOrFail($groupId);

        return $group->getPerformanceStats();
    }

    /**
     * Validate artist data
     */
    private function validateArtistData(array $data, ?int $excludeId = null): void
    {
        if (empty($data['name'])) {
            throw new ArtistManagementException('Artist name is required');
        }

        // Check for duplicate names within tenant
        $query = Artist::where('tenant_id', tenant('id'))
            ->where('name', $data['name']);

        if (! empty($data['stage_name'])) {
            $query->orWhere('stage_name', $data['stage_name']);
        }

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw new ArtistManagementException('Artist with this name or stage name already exists');
        }
    }

    /**
     * Validate group data
     */
    private function validateGroupData(array $data, ?int $excludeId = null): void
    {
        if (empty($data['name'])) {
            throw new ArtistManagementException('Group name is required');
        }

        // Check for duplicate names within tenant
        $query = Group::where('tenant_id', tenant('id'))
            ->where('name', $data['name']);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw new ArtistManagementException('Group with this name already exists');
        }
    }
}
