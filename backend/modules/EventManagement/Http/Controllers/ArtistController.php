<?php

namespace Modules\EventManagement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\EventManagement\Exceptions\ArtistManagementException;
use Modules\EventManagement\Http\Requests\CreateArtistRequest;
use Modules\EventManagement\Http\Requests\SearchArtistsRequest;
use Modules\EventManagement\Http\Requests\UpdateArtistRequest;
use Modules\EventManagement\Models\Artist;
use Modules\EventManagement\Services\ArtistManagementService;

/**
 * Artist Controller
 *
 * Handles HTTP requests for artist management operations including
 * CRUD operations, search, filtering, and performance history.
 *
 * Requirements: 12.1, 12.2, 12.4
 */
class ArtistController extends Controller
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
     * Get paginated list of artists with optional filtering
     */
    public function index(SearchArtistsRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $perPage = $request->input('per_page', 15);

            $artists = $this->artistManagementService->searchArtists($filters, $perPage);

            Log::info('Artists retrieved successfully', [
                'filters' => $filters,
                'total' => $artists->total(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'artists' => $artists->items(),
                    'pagination' => [
                        'current_page' => $artists->currentPage(),
                        'last_page' => $artists->lastPage(),
                        'per_page' => $artists->perPage(),
                        'total' => $artists->total(),
                        'from' => $artists->firstItem(),
                        'to' => $artists->lastItem(),
                    ],
                ],
                'message' => 'Artists retrieved successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve artists', [
                'error' => $e->getMessage(),
                'filters' => $request->all(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve artists',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get a specific artist by ID
     */
    public function show(int $id): JsonResponse
    {
        try {
            $artist = Artist::with(['groups', 'artistEvents.event'])->findOrFail($id);

            Log::info('Artist retrieved successfully', [
                'artist_id' => $id,
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'artist' => $artist,
                    'performance_stats' => $artist->getPerformanceStats(),
                    'active_groups' => $artist->getActiveGroups(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve artist', [
                'artist_id' => $id,
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Artist not found',
                'error' => $e->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Create a new artist
     */
    public function store(CreateArtistRequest $request): JsonResponse
    {
        try {
            $artistData = $request->validated();
            $artist = $this->artistManagementService->createArtist($artistData);

            Log::info('Artist created successfully', [
                'artist_id' => $artist->id,
                'name' => $artist->name,
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => true,
                'data' => ['artist' => $artist],
                'message' => 'Artist created successfully',
            ], Response::HTTP_CREATED);

        } catch (ArtistManagementException $e) {
            Log::warning('Artist creation failed - validation error', [
                'error' => $e->getMessage(),
                'data' => $request->validated(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ], $e->getHttpStatusCode());

        } catch (\Exception $e) {
            Log::error('Artist creation failed - system error', [
                'error' => $e->getMessage(),
                'data' => $request->validated(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create artist',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update an existing artist
     */
    public function update(UpdateArtistRequest $request, int $id): JsonResponse
    {
        try {
            $artistData = $request->validated();
            $artist = $this->artistManagementService->updateArtist($id, $artistData);

            Log::info('Artist updated successfully', [
                'artist_id' => $id,
                'changes' => $artist->getChanges(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => true,
                'data' => ['artist' => $artist],
                'message' => 'Artist updated successfully',
            ]);

        } catch (ArtistManagementException $e) {
            Log::warning('Artist update failed - validation error', [
                'artist_id' => $id,
                'error' => $e->getMessage(),
                'data' => $request->validated(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ], $e->getHttpStatusCode());

        } catch (\Exception $e) {
            Log::error('Artist update failed - system error', [
                'artist_id' => $id,
                'error' => $e->getMessage(),
                'data' => $request->validated(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update artist',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete an artist (soft delete)
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->artistManagementService->deleteArtist($id);

            Log::info('Artist deleted successfully', [
                'artist_id' => $id,
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Artist deleted successfully',
            ]);

        } catch (ArtistManagementException $e) {
            Log::warning('Artist deletion failed - business rule violation', [
                'artist_id' => $id,
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ], $e->getHttpStatusCode());

        } catch (\Exception $e) {
            Log::error('Artist deletion failed - system error', [
                'artist_id' => $id,
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete artist',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get artist performance history
     */
    public function performanceHistory(int $id): JsonResponse
    {
        try {
            $history = $this->artistManagementService->getArtistPerformanceHistory($id);
            $stats = $this->artistManagementService->getArtistPerformanceStats($id);

            Log::info('Artist performance history retrieved', [
                'artist_id' => $id,
                'performance_count' => $history->count(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'performance_history' => $history,
                    'performance_stats' => $stats,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve artist performance history', [
                'artist_id' => $id,
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve performance history',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get artist's active groups
     */
    public function activeGroups(int $id): JsonResponse
    {
        try {
            $artist = Artist::findOrFail($id);
            $activeGroups = $artist->getActiveGroups();

            Log::info('Artist active groups retrieved', [
                'artist_id' => $id,
                'group_count' => $activeGroups->count(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => true,
                'data' => ['active_groups' => $activeGroups],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve artist active groups', [
                'artist_id' => $id,
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve active groups',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Search artists by name or stage name
     */
    public function search(Request $request): JsonResponse
    {
        try {
            $searchTerm = $request->input('q', '');
            $limit = min($request->input('limit', 10), 50); // Max 50 results

            if (strlen($searchTerm) < 2) {
                return response()->json([
                    'success' => true,
                    'data' => ['artists' => []],
                    'message' => 'Search term must be at least 2 characters',
                ]);
            }

            $artists = Artist::search($searchTerm)
                ->active()
                ->select(['id', 'name', 'stage_name', 'profile_image_url', 'agency'])
                ->limit($limit)
                ->get();

            Log::info('Artist search completed', [
                'search_term' => $searchTerm,
                'results_count' => $artists->count(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => true,
                'data' => ['artists' => $artists],
            ]);

        } catch (\Exception $e) {
            Log::error('Artist search failed', [
                'search_term' => $request->input('q'),
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Search failed',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get artists by agency
     */
    public function byAgency(string $agency): JsonResponse
    {
        try {
            $artists = Artist::byAgency($agency)
                ->active()
                ->select(['id', 'name', 'stage_name', 'profile_image_url', 'debut_date'])
                ->orderBy('debut_date', 'desc')
                ->get();

            Log::info('Artists by agency retrieved', [
                'agency' => $agency,
                'artist_count' => $artists->count(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'agency' => $agency,
                    'artists' => $artists,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve artists by agency', [
                'agency' => $agency,
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve artists by agency',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get solo artists (not in any active group)
     */
    public function soloArtists(): JsonResponse
    {
        try {
            $soloArtists = Artist::solo()
                ->active()
                ->select(['id', 'name', 'stage_name', 'profile_image_url', 'debut_date', 'agency'])
                ->orderBy('name')
                ->get();

            Log::info('Solo artists retrieved', [
                'solo_artist_count' => $soloArtists->count(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => true,
                'data' => ['solo_artists' => $soloArtists],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve solo artists', [
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve solo artists',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get artist statistics summary
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = [
                'total_artists' => Artist::count(),
                'active_artists' => Artist::active()->count(),
                'solo_artists' => Artist::solo()->count(),
                'group_members' => Artist::whereHas('groups', function ($q) {
                    $q->wherePivot('status', 'active');
                })->count(),
                'by_agency' => Artist::active()
                    ->selectRaw('agency, COUNT(*) as count')
                    ->whereNotNull('agency')
                    ->groupBy('agency')
                    ->orderBy('count', 'desc')
                    ->limit(10)
                    ->get(),
                'by_debut_year' => Artist::active()
                    ->selectRaw('YEAR(debut_date) as year, COUNT(*) as count')
                    ->whereNotNull('debut_date')
                    ->groupBy('year')
                    ->orderBy('year', 'desc')
                    ->limit(10)
                    ->get(),
            ];

            Log::info('Artist statistics retrieved', [
                'total_artists' => $stats['total_artists'],
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => true,
                'data' => ['statistics' => $stats],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve artist statistics', [
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve statistics',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
