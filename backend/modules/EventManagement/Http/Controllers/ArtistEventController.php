<?php

namespace Modules\EventManagement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\EventManagement\Exceptions\ArtistManagementException;
use Modules\EventManagement\Models\ArtistEvent;
use Modules\EventManagement\Services\ArtistManagementService;
use Modules\EventManagement\Services\EventService;

/**
 * Artist Event Controller
 *
 * Handles HTTP requests for managing artist-event associations,
 * including linking artists/groups to events and managing performance details.
 *
 * Requirements: 12.2, 12.3, 12.4
 */
class ArtistEventController extends Controller
{
    private EventService $eventService;

    private ArtistManagementService $artistManagementService;

    public function __construct(EventService $eventService, ArtistManagementService $artistManagementService)
    {
        $this->eventService = $eventService;
        $this->artistManagementService = $artistManagementService;

        // Apply RBAC middleware - admin only for management, public for viewing
        $this->middleware('auth:sanctum')->except(['eventsByArtist', 'eventsByGroup', 'searchByArtist']);
        $this->middleware('role:admin')->except(['eventsByArtist', 'eventsByGroup', 'searchByArtist']);
        $this->middleware('tenant.scope');
    }

    /**
     * Link artist to event
     */
    public function linkArtist(Request $request, int $eventId): JsonResponse
    {
        try {
            $request->validate([
                'artist_id' => 'required|integer|exists:artists,id',
                'role' => 'nullable|string|in:main,guest,collaboration',
                'performance_order' => 'nullable|integer|min:1',
                'performance_duration' => 'nullable|integer|min:1',
                'setlist' => 'nullable|array',
                'billing_name' => 'nullable|string|max:255',
                'special_notes' => 'nullable|string|max:1000',
                'performance_fee' => 'nullable|numeric|min:0',
                'technical_requirements' => 'nullable|array',
                'status' => 'nullable|string|in:tentative,confirmed,cancelled',
            ]);

            $artistData = $request->all();
            $artistEvent = $this->eventService->linkArtistToEvent($eventId, $artistData);

            Log::info('Artist linked to event successfully', [
                'event_id' => $eventId,
                'artist_id' => $artistData['artist_id'],
                'role' => $artistData['role'] ?? 'main',
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => true,
                'data' => ['artist_event' => $artistEvent->load(['artist', 'event'])],
                'message' => 'Artist linked to event successfully',
            ], Response::HTTP_CREATED);

        } catch (ArtistManagementException $e) {
            Log::warning('Artist link to event failed - validation error', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
                'data' => $request->all(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ], $e->getHttpStatusCode());

        } catch (\Exception $e) {
            Log::error('Artist link to event failed - system error', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
                'data' => $request->all(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to link artist to event',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Link group to event
     */
    public function linkGroup(Request $request, int $eventId): JsonResponse
    {
        try {
            $request->validate([
                'group_id' => 'required|integer|exists:artist_groups,id',
                'role' => 'nullable|string|in:main,guest,collaboration',
                'performance_order' => 'nullable|integer|min:1',
                'performance_duration' => 'nullable|integer|min:1',
                'setlist' => 'nullable|array',
                'billing_name' => 'nullable|string|max:255',
                'special_notes' => 'nullable|string|max:1000',
                'performance_fee' => 'nullable|numeric|min:0',
                'technical_requirements' => 'nullable|array',
                'status' => 'nullable|string|in:tentative,confirmed,cancelled',
            ]);

            $groupData = $request->all();
            $artistEvent = $this->eventService->linkGroupToEvent($eventId, $groupData);

            Log::info('Group linked to event successfully', [
                'event_id' => $eventId,
                'group_id' => $groupData['group_id'],
                'role' => $groupData['role'] ?? 'main',
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => true,
                'data' => ['artist_event' => $artistEvent->load(['group', 'event'])],
                'message' => 'Group linked to event successfully',
            ], Response::HTTP_CREATED);

        } catch (ArtistManagementException $e) {
            Log::warning('Group link to event failed - validation error', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
                'data' => $request->all(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ], $e->getHttpStatusCode());

        } catch (\Exception $e) {
            Log::error('Group link to event failed - system error', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
                'data' => $request->all(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to link group to event',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update artist-event association
     */
    public function updateAssociation(Request $request, int $artistEventId): JsonResponse
    {
        try {
            $request->validate([
                'role' => 'nullable|string|in:main,guest,collaboration',
                'performance_order' => 'nullable|integer|min:1',
                'performance_duration' => 'nullable|integer|min:1',
                'setlist' => 'nullable|array',
                'billing_name' => 'nullable|string|max:255',
                'special_notes' => 'nullable|string|max:1000',
                'performance_fee' => 'nullable|numeric|min:0',
                'technical_requirements' => 'nullable|array',
                'status' => 'nullable|string|in:tentative,confirmed,cancelled',
            ]);

            $artistEvent = ArtistEvent::findOrFail($artistEventId);
            $artistEvent->update($request->validated());

            Log::info('Artist-event association updated successfully', [
                'artist_event_id' => $artistEventId,
                'changes' => $artistEvent->getChanges(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => true,
                'data' => ['artist_event' => $artistEvent->load(['artist', 'group', 'event'])],
                'message' => 'Association updated successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Artist-event association update failed', [
                'artist_event_id' => $artistEventId,
                'error' => $e->getMessage(),
                'data' => $request->all(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update association',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove artist/group from event
     */
    public function removeAssociation(int $artistEventId): JsonResponse
    {
        try {
            $artistEvent = ArtistEvent::findOrFail($artistEventId);
            $artistEvent->update(['status' => 'cancelled']);

            Log::info('Artist-event association removed successfully', [
                'artist_event_id' => $artistEventId,
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Association removed successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Artist-event association removal failed', [
                'artist_event_id' => $artistEventId,
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove association',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get events by artist (public endpoint)
     */
    public function eventsByArtist(Request $request, int $artistId): JsonResponse
    {
        try {
            $filters = $request->only(['from_date', 'to_date', 'status']);
            $events = $this->eventService->getEventsByArtist($artistId, $filters);

            Log::info('Events by artist retrieved', [
                'artist_id' => $artistId,
                'event_count' => $events->count(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => true,
                'data' => ['events' => $events],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve events by artist', [
                'artist_id' => $artistId,
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve events',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get events by group (public endpoint)
     */
    public function eventsByGroup(Request $request, int $groupId): JsonResponse
    {
        try {
            $filters = $request->only(['from_date', 'to_date', 'status']);
            $events = $this->eventService->getEventsByGroup($groupId, $filters);

            Log::info('Events by group retrieved', [
                'group_id' => $groupId,
                'event_count' => $events->count(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => true,
                'data' => ['events' => $events],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve events by group', [
                'group_id' => $groupId,
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve events',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Search events by artist name (public endpoint)
     */
    public function searchByArtist(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'q' => 'required|string|min:2|max:255',
                'from_date' => 'nullable|date',
                'to_date' => 'nullable|date|after_or_equal:from_date',
            ]);

            $searchTerm = $request->input('q');
            $filters = $request->only(['from_date', 'to_date']);

            $events = $this->eventService->searchEventsByArtist($searchTerm, $filters);

            Log::info('Artist event search completed', [
                'search_term' => $searchTerm,
                'results_count' => $events->count(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => true,
                'data' => ['events' => $events],
            ]);

        } catch (\Exception $e) {
            Log::error('Artist event search failed', [
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
     * Get event lineup (artists and groups for an event)
     */
    public function getEventLineup(int $eventId): JsonResponse
    {
        try {
            $event = $this->eventService->getEventById($eventId);

            $lineup = $event->artistEvents()
                ->whereIn('status', ['confirmed', 'tentative'])
                ->with(['artist', 'group'])
                ->orderBy('performance_order', 'asc')
                ->get();

            Log::info('Event lineup retrieved', [
                'event_id' => $eventId,
                'lineup_count' => $lineup->count(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'event' => $event->only(['id', 'title', 'start_date', 'venue']),
                    'lineup' => $lineup,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve event lineup', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve event lineup',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
