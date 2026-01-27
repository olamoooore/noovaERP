<?php

namespace Modules\EventManagement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\EventManagement\Http\Requests\CreateGroupRequest;
use Modules\EventManagement\Http\Requests\UpdateGroupRequest;
use Modules\EventManagement\Http\Requests\SearchGroupsRequest;
use Modules\EventManagement\Http\Requests\AddGroupMemberRequest;
use Modules\EventManagement\Services\ArtistManagementService;
use Modules\EventManagement\Models\Group;
use Modules\EventManagement\Exceptions\ArtistManagementException;

/**
 * Group Controller
 * 
 * Handles HTTP requests for K-POP group management operations including
 * CRUD operations, member management, search, and performance history.
 * 
 * Requirements: 12.1, 12.2, 12.4
 */
class GroupController extends Controller
{
    private ArtistManagementService $artistManagementService;

    public function __construct(ArtistManagementService $artistManagementService)
    {
        $this->artistManagementService = $artistManagementService;
        
        // Apply RBAC middleware - admin only
        $this->middleware('auth:sanctum');
        $this->middleware('role:admin');
        $this->middleware('tenant.scope');
    }

    /**
     * Get paginated list of groups with optional filtering
     */
    public function index(SearchGroupsRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $perPage = $request->input('per_page', 15);
            
            $groups = $this->artistManagementService->searchGroups($filters, $perPage);

            Log::info('Groups retrieved successfully', [
                'filters' => $filters,
                'total' => $groups->total(),
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'groups' => $groups->items(),
                    'pagination' => [
                        'current_page' => $groups->currentPage(),
                        'last_page' => $groups->lastPage(),
                        'per_page' => $groups->perPage(),
                        'total' => $groups->total(),
                        'from' => $groups->firstItem(),
                        'to' => $groups->lastItem(),
                    ]
                ],
                'message' => 'Groups retrieved successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve groups', [
                'error' => $e->getMessage(),
                'filters' => $request->all(),
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve groups',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get a specific group by ID
     */
    public function show(int $id): JsonResponse
    {
        try {
            $group = Group::with(['artists', 'artistEvents.event'])->findOrFail($id);

            Log::info('Group retrieved successfully', [
                'group_id' => $id,
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'group' => $group,
                    'performance_stats' => $group->getPerformanceStats(),
                    'active_members' => $group->getActiveMembers(),
                    'leader' => $group->getLeader(),
                    'main_vocalists' => $group->getMainVocalists(),
                    'main_dancers' => $group->getMainDancers(),
                    'main_rappers' => $group->getMainRappers(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve group', [
                'group_id' => $id,
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Group not found',
                'error' => $e->getMessage()
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Create a new group
     */
    public function store(CreateGroupRequest $request): JsonResponse
    {
        try {
            $groupData = $request->validated();
            $group = $this->artistManagementService->createGroup($groupData);

            Log::info('Group created successfully', [
                'group_id' => $group->id,
                'name' => $group->name,
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => true,
                'data' => ['group' => $group],
                'message' => 'Group created successfully'
            ], Response::HTTP_CREATED);

        } catch (ArtistManagementException $e) {
            Log::warning('Group creation failed - validation error', [
                'error' => $e->getMessage(),
                'data' => $request->validated(),
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->getErrorCode()
            ], $e->getHttpStatusCode());

        } catch (\Exception $e) {
            Log::error('Group creation failed - system error', [
                'error' => $e->getMessage(),
                'data' => $request->validated(),
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create group',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update an existing group
     */
    public function update(UpdateGroupRequest $request, int $id): JsonResponse
    {
        try {
            $groupData = $request->validated();
            $group = $this->artistManagementService->updateGroup($id, $groupData);

            Log::info('Group updated successfully', [
                'group_id' => $id,
                'changes' => $group->getChanges(),
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => true,
                'data' => ['group' => $group],
                'message' => 'Group updated successfully'
            ]);

        } catch (ArtistManagementException $e) {
            Log::warning('Group update failed - validation error', [
                'group_id' => $id,
                'error' => $e->getMessage(),
                'data' => $request->validated(),
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->getErrorCode()
            ], $e->getHttpStatusCode());

        } catch (\Exception $e) {
            Log::error('Group update failed - system error', [
                'group_id' => $id,
                'error' => $e->getMessage(),
                'data' => $request->validated(),
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update group',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete a group (soft delete)
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->artistManagementService->deleteGroup($id);

            Log::info('Group deleted successfully', [
                'group_id' => $id,
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Group deleted successfully'
            ]);

        } catch (ArtistManagementException $e) {
            Log::warning('Group deletion failed - business rule violation', [
                'group_id' => $id,
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->getErrorCode()
            ], $e->getHttpStatusCode());

        } catch (\Exception $e) {
            Log::error('Group deletion failed - system error', [
                'group_id' => $id,
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete group',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Add member to group
     */
    public function addMember(AddGroupMemberRequest $request, int $id): JsonResponse
    {
        try {
            $memberData = $request->validated();
            $artistId = $memberData['artist_id'];
            unset($memberData['artist_id']);

            $groupMember = $this->artistManagementService->addGroupMember($id, $artistId, $memberData);

            Log::info('Group member added successfully', [
                'group_id' => $id,
                'artist_id' => $artistId,
                'member_id' => $groupMember->id,
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => true,
                'data' => ['group_member' => $groupMember->load(['artist', 'group'])],
                'message' => 'Member added to group successfully'
            ], Response::HTTP_CREATED);

        } catch (ArtistManagementException $e) {
            Log::warning('Add group member failed - validation error', [
                'group_id' => $id,
                'error' => $e->getMessage(),
                'data' => $request->validated(),
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->getErrorCode()
            ], $e->getHttpStatusCode());

        } catch (\Exception $e) {
            Log::error('Add group member failed - system error', [
                'group_id' => $id,
                'error' => $e->getMessage(),
                'data' => $request->validated(),
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to add member to group',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove member from group
     */
    public function removeMember(Request $request, int $id, int $artistId): JsonResponse
    {
        try {
            $reason = $request->input('reason');
            $this->artistManagementService->removeGroupMember($id, $artistId, $reason);

            Log::info('Group member removed successfully', [
                'group_id' => $id,
                'artist_id' => $artistId,
                'reason' => $reason,
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Member removed from group successfully'
            ]);

        } catch (ArtistManagementException $e) {
            Log::warning('Remove group member failed - validation error', [
                'group_id' => $id,
                'artist_id' => $artistId,
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->getErrorCode()
            ], $e->getHttpStatusCode());

        } catch (\Exception $e) {
            Log::error('Remove group member failed - system error', [
                'group_id' => $id,
                'artist_id' => $artistId,
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove member from group',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get group performance history
     */
    public function performanceHistory(int $id): JsonResponse
    {
        try {
            $history = $this->artistManagementService->getGroupPerformanceHistory($id);
            $stats = $this->artistManagementService->getGroupPerformanceStats($id);

            Log::info('Group performance history retrieved', [
                'group_id' => $id,
                'performance_count' => $history->count(),
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'performance_history' => $history,
                    'performance_stats' => $stats
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve group performance history', [
                'group_id' => $id,
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve performance history',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get group members with details
     */
    public function members(int $id): JsonResponse
    {
        try {
            $group = Group::findOrFail($id);
            $activeMembers = $group->getActiveMembers();
            $allMembers = $group->artists()->withPivot([
                'stage_name_in_group', 'positions', 'is_leader', 'is_main_vocalist',
                'is_main_dancer', 'is_main_rapper', 'member_number', 'joined_date',
                'left_date', 'status', 'departure_reason'
            ])->get();

            Log::info('Group members retrieved', [
                'group_id' => $id,
                'active_member_count' => $activeMembers->count(),
                'total_member_count' => $allMembers->count(),
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'active_members' => $activeMembers,
                    'all_members' => $allMembers,
                    'member_count' => $group->member_count,
                    'leader' => $group->getLeader(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve group members', [
                'group_id' => $id,
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve group members',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Search groups by name
     */
    public function search(Request $request): JsonResponse
    {
        try {
            $searchTerm = $request->input('q', '');
            $limit = min($request->input('limit', 10), 50); // Max 50 results

            if (strlen($searchTerm) < 2) {
                return response()->json([
                    'success' => true,
                    'data' => ['groups' => []],
                    'message' => 'Search term must be at least 2 characters'
                ]);
            }

            $groups = Group::search($searchTerm)
                ->active()
                ->select(['id', 'name', 'korean_name', 'english_name', 'profile_image_url', 'agency', 'member_count'])
                ->limit($limit)
                ->get();

            Log::info('Group search completed', [
                'search_term' => $searchTerm,
                'results_count' => $groups->count(),
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => true,
                'data' => ['groups' => $groups]
            ]);

        } catch (\Exception $e) {
            Log::error('Group search failed', [
                'search_term' => $request->input('q'),
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Search failed',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get groups by agency
     */
    public function byAgency(string $agency): JsonResponse
    {
        try {
            $groups = Group::byAgency($agency)
                ->active()
                ->select(['id', 'name', 'korean_name', 'profile_image_url', 'debut_date', 'member_count'])
                ->orderBy('debut_date', 'desc')
                ->get();

            Log::info('Groups by agency retrieved', [
                'agency' => $agency,
                'group_count' => $groups->count(),
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'agency' => $agency,
                    'groups' => $groups
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve groups by agency', [
                'agency' => $agency,
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve groups by agency',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get group statistics summary
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = [
                'total_groups' => Group::count(),
                'active_groups' => Group::active()->count(),
                'average_member_count' => Group::active()->avg('member_count'),
                'by_agency' => Group::active()
                    ->selectRaw('agency, COUNT(*) as count')
                    ->whereNotNull('agency')
                    ->groupBy('agency')
                    ->orderBy('count', 'desc')
                    ->limit(10)
                    ->get(),
                'by_debut_year' => Group::active()
                    ->selectRaw('YEAR(debut_date) as year, COUNT(*) as count')
                    ->whereNotNull('debut_date')
                    ->groupBy('year')
                    ->orderBy('year', 'desc')
                    ->limit(10)
                    ->get(),
                'by_member_count' => Group::active()
                    ->selectRaw('
                        CASE 
                            WHEN member_count <= 3 THEN "1-3 members"
                            WHEN member_count <= 5 THEN "4-5 members"
                            WHEN member_count <= 7 THEN "6-7 members"
                            WHEN member_count <= 10 THEN "8-10 members"
                            ELSE "10+ members"
                        END as size_category,
                        COUNT(*) as count
                    ')
                    ->groupBy('size_category')
                    ->orderBy('count', 'desc')
                    ->get(),
            ];

            Log::info('Group statistics retrieved', [
                'total_groups' => $stats['total_groups'],
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => true,
                'data' => ['statistics' => $stats]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve group statistics', [
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve statistics',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}