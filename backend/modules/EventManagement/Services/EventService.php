<?php

namespace Modules\EventManagement\Services;

use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\EventTicketType;
use Modules\EventManagement\Models\Artist;
use Modules\EventManagement\Models\Group;
use Modules\EventManagement\Models\ArtistEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class EventService
{
    protected AuditService $auditService;
    protected ArtistManagementService $artistManagementService;
    protected CapacityReservationService $capacityReservationService;

    public function __construct(
        AuditService $auditService, 
        ArtistManagementService $artistManagementService,
        CapacityReservationService $capacityReservationService
    ) {
        $this->auditService = $auditService;
        $this->artistManagementService = $artistManagementService;
        $this->capacityReservationService = $capacityReservationService;
    }

    /**
     * Create a new event with optional artist associations
     * Requirements: 1.1, 12.2 - Store event details and link artists/groups
     */
    public function createEvent(array $eventData): Event
    {
        return DB::transaction(function () use ($eventData) {
            // Create the event
            $event = Event::create([
                'tenant_id' => tenant('id'),
                'title' => $eventData['title'],
                'description' => $eventData['description'] ?? null,
                'start_date' => Carbon::parse($eventData['start_date']),
                'end_date' => isset($eventData['end_date']) ? Carbon::parse($eventData['end_date']) : null,
                'venue' => $eventData['venue'],
                'venue_address' => $eventData['venue_address'] ?? null,
                'total_capacity' => $eventData['total_capacity'],
                'available_capacity' => $eventData['total_capacity'], // Initially same as total
                'status' => 'draft', // Always start as draft
                'terms_conditions' => $eventData['terms_conditions'] ?? null,
                'event_type' => $eventData['event_type'] ?? 'concert',
                'age_restriction' => $eventData['age_restriction'] ?? null,
                'metadata' => $eventData['metadata'] ?? [],
            ]);

            // Create ticket types if provided
            if (isset($eventData['ticket_types']) && is_array($eventData['ticket_types'])) {
                foreach ($eventData['ticket_types'] as $ticketTypeData) {
                    EventTicketType::create([
                        'tenant_id' => tenant('id'),
                        'event_id' => $event->id,
                        'name' => $ticketTypeData['name'],
                        'price' => $ticketTypeData['price'],
                        'capacity' => $ticketTypeData['capacity'],
                        'available_capacity' => $ticketTypeData['capacity'],
                        'description' => $ticketTypeData['description'] ?? null,
                        'sale_start_date' => isset($ticketTypeData['sale_start_date']) 
                            ? Carbon::parse($ticketTypeData['sale_start_date']) 
                            : null,
                        'sale_end_date' => isset($ticketTypeData['sale_end_date']) 
                            ? Carbon::parse($ticketTypeData['sale_end_date']) 
                            : null,
                    ]);
                }
            }

            // Link artists if provided
            if (isset($eventData['artists']) && is_array($eventData['artists'])) {
                foreach ($eventData['artists'] as $artistData) {
                    $this->linkArtistToEvent($event->id, $artistData);
                }
            }

            // Link groups if provided
            if (isset($eventData['groups']) && is_array($eventData['groups'])) {
                foreach ($eventData['groups'] as $groupData) {
                    $this->linkGroupToEvent($event->id, $groupData);
                }
            }

            // Log the event creation
            $this->auditService->logActivity(
                'event_created',
                Event::class,
                $event->id,
                [
                    'event_data' => $eventData,
                    'artists_count' => count($eventData['artists'] ?? []),
                    'groups_count' => count($eventData['groups'] ?? [])
                ]
            );

            Log::info('Event created successfully', [
                'event_id' => $event->id,
                'title' => $event->title,
                'tenant_id' => tenant('id')
            ]);

            return $event->load(['ticketTypes', 'artistEvents.artist', 'artistEvents.group']);
        });
    }

    /**
     * Publish an event
     * Requirements: 1.2 - Make event visible on public events listing page
     */
    public function publishEvent(int $eventId): Event
    {
        return DB::transaction(function () use ($eventId) {
            $event = Event::findOrFail($eventId);
            
            // Only allow publishing from draft status
            if ($event->status !== 'draft') {
                throw new \InvalidArgumentException("Event can only be published from draft status");
            }

            $event->update(['status' => 'published']);

            // Log the event publishing
            $this->auditService->logActivity(
                'event_published',
                'event',
                $event->id,
                ['previous_status' => 'draft', 'new_status' => 'published']
            );

            return $event;
        });
    }

    /**
     * Update event capacity
     * Requirements: 1.4 - Enforce capacity limits for each event
     */
    public function updateCapacity(int $eventId, int $capacity): Event
    {
        return DB::transaction(function () use ($eventId, $capacity) {
            $event = Event::findOrFail($eventId);
            
            // Calculate current bookings to ensure we don't set capacity below current bookings
            $currentBookings = $event->total_capacity - $event->available_capacity;
            
            if ($capacity < $currentBookings) {
                throw new \InvalidArgumentException("Cannot set capacity below current bookings ({$currentBookings})");
            }

            $previousCapacity = $event->total_capacity;
            $newAvailableCapacity = $capacity - $currentBookings;

            $event->update([
                'total_capacity' => $capacity,
                'available_capacity' => $newAvailableCapacity,
            ]);

            // Update status if needed
            if ($newAvailableCapacity <= 0 && $event->status === 'published') {
                $event->update(['status' => 'sold_out']);
            } elseif ($newAvailableCapacity > 0 && $event->status === 'sold_out') {
                $event->update(['status' => 'published']);
            }

            // Log the capacity update
            $this->auditService->logActivity(
                'event_capacity_updated',
                'event',
                $event->id,
                [
                    'previous_capacity' => $previousCapacity,
                    'new_capacity' => $capacity,
                    'available_capacity' => $newAvailableCapacity
                ]
            );

            return $event;
        });
    }

    /**
     * Get public events with artist information
     * Requirements: 1.2, 12.3 - Display published events with artist details
     */
    public function getPublicEvents(array $filters = []): Collection
    {
        $query = Event::where('status', 'published')
            ->where('start_date', '>', Carbon::now())
            ->with(['ticketTypes', 'artistEvents.artist', 'artistEvents.group']);

        // Apply artist filter
        if (!empty($filters['artist_id'])) {
            $query->whereHas('artistEvents', function ($q) use ($filters) {
                $q->where('artist_id', $filters['artist_id']);
            });
        }

        // Apply group filter
        if (!empty($filters['group_id'])) {
            $query->whereHas('artistEvents', function ($q) use ($filters) {
                $q->where('group_id', $filters['group_id']);
            });
        }

        // Apply event type filter
        if (!empty($filters['event_type'])) {
            $query->where('event_type', $filters['event_type']);
        }

        // Apply venue filter
        if (!empty($filters['venue'])) {
            $query->where('venue', 'like', '%' . $filters['venue'] . '%');
        }

        return $query->orderBy('start_date', 'asc')->get();
    }

    /**
     * Check event availability
     * Requirements: 1.4, 1.5 - Check capacity and prevent overbooking
     * Phase 3.1 Task 3.4: Enhanced with real-time capacity calculations
     */
    public function checkAvailability(int $eventId, int $requestedTickets): bool
    {
        $event = Event::findOrFail($eventId);
        
        // Check if event can accept bookings
        if (!$event->canAcceptBookings()) {
            return false;
        }

        // Use CapacityReservationService for real-time capacity calculation
        $realTimeAvailableCapacity = $this->capacityReservationService->calculateRealTimeAvailableCapacity($eventId);
        
        // Check if enough capacity is available
        return $realTimeAvailableCapacity >= $requestedTickets;
    }

    /**
     * Get event by ID with artist and group information
     * Requirements: 12.3 - Display artist information on event details
     */
    public function getEventById(int $eventId): Event
    {
        return Event::with([
            'ticketTypes', 
            'artistEvents.artist', 
            'artistEvents.group',
            'artistEvents' => function ($query) {
                $query->orderBy('performance_order', 'asc');
            }
        ])->findOrFail($eventId);
    }

    /**
     * Update event details with artist associations
     * Requirements: 12.2, 12.3 - Update event and artist information
     */
    public function updateEvent(int $eventId, array $eventData): Event
    {
        return DB::transaction(function () use ($eventId, $eventData) {
            $event = Event::findOrFail($eventId);
            
            $previousData = $event->toArray();
            
            $event->update([
                'title' => $eventData['title'] ?? $event->title,
                'description' => $eventData['description'] ?? $event->description,
                'start_date' => isset($eventData['start_date']) ? Carbon::parse($eventData['start_date']) : $event->start_date,
                'end_date' => isset($eventData['end_date']) ? Carbon::parse($eventData['end_date']) : $event->end_date,
                'venue' => $eventData['venue'] ?? $event->venue,
                'venue_address' => $eventData['venue_address'] ?? $event->venue_address,
                'terms_conditions' => $eventData['terms_conditions'] ?? $event->terms_conditions,
                'event_type' => $eventData['event_type'] ?? $event->event_type,
                'age_restriction' => $eventData['age_restriction'] ?? $event->age_restriction,
                'metadata' => $eventData['metadata'] ?? $event->metadata,
            ]);

            // Update artist associations if provided
            if (isset($eventData['artists'])) {
                $this->updateEventArtists($eventId, $eventData['artists']);
            }

            // Update group associations if provided
            if (isset($eventData['groups'])) {
                $this->updateEventGroups($eventId, $eventData['groups']);
            }

            // Log the event update
            $this->auditService->logActivity(
                'event_updated',
                Event::class,
                $event->id,
                [
                    'previous_data' => $previousData,
                    'updated_data' => $eventData
                ]
            );

            Log::info('Event updated successfully', [
                'event_id' => $eventId,
                'changes' => $event->getChanges(),
                'tenant_id' => tenant('id')
            ]);

            return $event->load(['ticketTypes', 'artistEvents.artist', 'artistEvents.group']);
        });
    }

    /**
     * Mark event as sold out
     * Requirements: 1.5 - Display sold-out status when capacity reached
     */
    public function markAsSoldOut(int $eventId): Event
    {
        return DB::transaction(function () use ($eventId) {
            $event = Event::findOrFail($eventId);
            
            if ($event->status === 'published') {
                $event->update(['status' => 'sold_out']);
                
                // Log the status change
                $this->auditService->logActivity(
                    'event_sold_out',
                    'event',
                    $event->id,
                    ['previous_status' => 'published', 'new_status' => 'sold_out']
                );
            }

            return $event;
        });
    }

    /**
     * Get all events with artist information for admin view
     * Requirements: 12.4 - Admin view with artist performance tracking
     */
    public function getAllEvents(array $filters = []): Collection
    {
        $query = Event::with(['ticketTypes', 'artistEvents.artist', 'artistEvents.group']);

        // Apply filters
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['from_date'])) {
            $query->where('start_date', '>=', Carbon::parse($filters['from_date']));
        }

        if (isset($filters['to_date'])) {
            $query->where('start_date', '<=', Carbon::parse($filters['to_date']));
        }

        // Apply artist filter
        if (!empty($filters['artist_id'])) {
            $query->whereHas('artistEvents', function ($q) use ($filters) {
                $q->where('artist_id', $filters['artist_id']);
            });
        }

        // Apply group filter
        if (!empty($filters['group_id'])) {
            $query->whereHas('artistEvents', function ($q) use ($filters) {
                $q->where('group_id', $filters['group_id']);
            });
        }

        return $query->orderBy('start_date', 'asc')->get();
    }

    /**
     * Delete an event (soft delete in real implementation)
     */
    public function deleteEvent(int $eventId): bool
    {
        return DB::transaction(function () use ($eventId) {
            $event = Event::findOrFail($eventId);
            
            // Check if event has bookings
            if ($event->bookings()->exists()) {
                throw new \InvalidArgumentException("Cannot delete event with existing bookings");
            }

            // Log the event deletion
            $this->auditService->logActivity(
                'event_deleted',
                'event',
                $event->id,
                ['event_data' => $event->toArray()]
            );

            return $event->delete();
        });
    }

    /**
     * Link artist to event
     * Requirements: 12.2 - Support artist-event associations
     */
    public function linkArtistToEvent(int $eventId, array $artistData): ArtistEvent
    {
        $artistId = $artistData['artist_id'];
        $role = $artistData['role'] ?? 'main';
        $performanceData = $artistData['performance_data'] ?? [];

        return $this->artistManagementService->linkArtistToEvent($artistId, $eventId, $role, $performanceData);
    }

    /**
     * Link group to event
     * Requirements: 12.2 - Support group-event associations
     */
    public function linkGroupToEvent(int $eventId, array $groupData): ArtistEvent
    {
        $groupId = $groupData['group_id'];
        $role = $groupData['role'] ?? 'main';
        $performanceData = $groupData['performance_data'] ?? [];

        return $this->artistManagementService->linkGroupToEvent($groupId, $eventId, $role, $performanceData);
    }

    /**
     * Update event artist associations
     * Requirements: 12.2 - Manage artist-event relationships
     */
    public function updateEventArtists(int $eventId, array $artistsData): void
    {
        DB::transaction(function () use ($eventId, $artistsData) {
            // Get current artist associations
            $currentArtistEvents = ArtistEvent::where('event_id', $eventId)
                ->whereNotNull('artist_id')
                ->get();

            $providedArtistIds = collect($artistsData)->pluck('artist_id')->filter();

            // Remove artists not in the new list
            $currentArtistEvents->whereNotIn('artist_id', $providedArtistIds)
                ->each(function ($artistEvent) {
                    $artistEvent->update(['status' => 'cancelled']);
                    
                    $this->auditService->logActivity(
                        'artist_removed_from_event',
                        ArtistEvent::class,
                        $artistEvent->id,
                        ['reason' => 'event_update']
                    );
                });

            // Add or update artists
            foreach ($artistsData as $artistData) {
                $existingAssociation = $currentArtistEvents
                    ->where('artist_id', $artistData['artist_id'])
                    ->first();

                if ($existingAssociation) {
                    // Update existing association
                    $existingAssociation->update([
                        'role' => $artistData['role'] ?? $existingAssociation->role,
                        'performance_order' => $artistData['performance_order'] ?? $existingAssociation->performance_order,
                        'status' => 'confirmed'
                    ]);
                } else {
                    // Create new association
                    $this->linkArtistToEvent($eventId, $artistData);
                }
            }
        });
    }

    /**
     * Update event group associations
     * Requirements: 12.2 - Manage group-event relationships
     */
    public function updateEventGroups(int $eventId, array $groupsData): void
    {
        DB::transaction(function () use ($eventId, $groupsData) {
            // Get current group associations
            $currentGroupEvents = ArtistEvent::where('event_id', $eventId)
                ->whereNotNull('group_id')
                ->get();

            $providedGroupIds = collect($groupsData)->pluck('group_id')->filter();

            // Remove groups not in the new list
            $currentGroupEvents->whereNotIn('group_id', $providedGroupIds)
                ->each(function ($groupEvent) {
                    $groupEvent->update(['status' => 'cancelled']);
                    
                    $this->auditService->logActivity(
                        'group_removed_from_event',
                        ArtistEvent::class,
                        $groupEvent->id,
                        ['reason' => 'event_update']
                    );
                });

            // Add or update groups
            foreach ($groupsData as $groupData) {
                $existingAssociation = $currentGroupEvents
                    ->where('group_id', $groupData['group_id'])
                    ->first();

                if ($existingAssociation) {
                    // Update existing association
                    $existingAssociation->update([
                        'role' => $groupData['role'] ?? $existingAssociation->role,
                        'performance_order' => $groupData['performance_order'] ?? $existingAssociation->performance_order,
                        'status' => 'confirmed'
                    ]);
                } else {
                    // Create new association
                    $this->linkGroupToEvent($eventId, $groupData);
                }
            }
        });
    }

    /**
     * Get events by artist
     * Requirements: 12.4 - Artist performance history tracking
     */
    public function getEventsByArtist(int $artistId, array $filters = []): Collection
    {
        $query = Event::whereHas('artistEvents', function ($q) use ($artistId) {
            $q->where('artist_id', $artistId)
              ->whereIn('status', ['confirmed', 'tentative']);
        })->with(['ticketTypes', 'artistEvents' => function ($q) use ($artistId) {
            $q->where('artist_id', $artistId);
        }]);

        // Apply date filters
        if (!empty($filters['from_date'])) {
            $query->where('start_date', '>=', Carbon::parse($filters['from_date']));
        }

        if (!empty($filters['to_date'])) {
            $query->where('start_date', '<=', Carbon::parse($filters['to_date']));
        }

        // Apply status filter
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderBy('start_date', 'desc')->get();
    }

    /**
     * Get events by group
     * Requirements: 12.4 - Group performance history tracking
     */
    public function getEventsByGroup(int $groupId, array $filters = []): Collection
    {
        $query = Event::whereHas('artistEvents', function ($q) use ($groupId) {
            $q->where('group_id', $groupId)
              ->whereIn('status', ['confirmed', 'tentative']);
        })->with(['ticketTypes', 'artistEvents' => function ($q) use ($groupId) {
            $q->where('group_id', $groupId);
        }]);

        // Apply date filters
        if (!empty($filters['from_date'])) {
            $query->where('start_date', '>=', Carbon::parse($filters['from_date']));
        }

        if (!empty($filters['to_date'])) {
            $query->where('start_date', '<=', Carbon::parse($filters['to_date']));
        }

        // Apply status filter
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderBy('start_date', 'desc')->get();
    }

    /**
     * Search events by artist or group name
     * Requirements: 12.3 - Artist-based event filtering
     */
    public function searchEventsByArtist(string $searchTerm, array $filters = []): Collection
    {
        $query = Event::where('status', 'published')
            ->where(function ($q) use ($searchTerm) {
                $q->whereHas('artistEvents.artist', function ($artistQuery) use ($searchTerm) {
                    $artistQuery->where('name', 'like', '%' . $searchTerm . '%')
                               ->orWhere('stage_name', 'like', '%' . $searchTerm . '%');
                })->orWhereHas('artistEvents.group', function ($groupQuery) use ($searchTerm) {
                    $groupQuery->where('name', 'like', '%' . $searchTerm . '%')
                              ->orWhere('korean_name', 'like', '%' . $searchTerm . '%')
                              ->orWhere('english_name', 'like', '%' . $searchTerm . '%');
                });
            })
            ->with(['ticketTypes', 'artistEvents.artist', 'artistEvents.group']);

        // Apply date filters
        if (!empty($filters['from_date'])) {
            $query->where('start_date', '>=', Carbon::parse($filters['from_date']));
        }

        if (!empty($filters['to_date'])) {
            $query->where('start_date', '<=', Carbon::parse($filters['to_date']));
        }

        return $query->orderBy('start_date', 'asc')->get();
    }
}