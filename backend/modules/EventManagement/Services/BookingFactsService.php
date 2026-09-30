<?php

namespace Modules\EventManagement\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\EventManagement\Models\Artist;
use Modules\EventManagement\Models\Booking;
use Modules\EventManagement\Models\BookingFacts;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\EventTicketType;
use Modules\EventManagement\Models\Group;

/**
 * BookingFactsService
 *
 * Captures immutable snapshots of all relevant data at booking time
 * to prevent analytics drift when master data changes.
 */
class BookingFactsService
{
    /**
     * Capture complete booking facts at booking creation time
     */
    public function captureBookingFacts(Booking $booking): BookingFacts
    {
        return DB::transaction(function () use ($booking) {
            // Load all related data
            $booking->load([
                'event',
                'eventTicketType',
                'bookingAttendees',
                'customerTier',
            ]);

            $event = $booking->event;
            $ticketType = $booking->eventTicketType;

            // Capture event snapshot
            $eventSnapshot = $this->captureEventSnapshot($event);

            // Capture ticket type snapshot
            $ticketTypeSnapshot = $this->captureTicketTypeSnapshot($ticketType);

            // Capture artist snapshot (if any)
            $artistSnapshot = $this->captureArtistSnapshot($event);

            // Capture pricing snapshot
            $pricingSnapshot = $this->capturePricingSnapshot($booking, $ticketType);

            // Capture tier snapshot (if customer has tier)
            $tierSnapshot = $this->captureTierSnapshot($booking);

            // Create metadata
            $metadata = $this->createMetadata($booking);

            // Create immutable facts record
            $bookingFacts = BookingFacts::create([
                'booking_id' => $booking->id,
                'event_snapshot' => $eventSnapshot,
                'ticket_type_snapshot' => $ticketTypeSnapshot,
                'artist_snapshot' => $artistSnapshot,
                'pricing_snapshot' => $pricingSnapshot,
                'tier_snapshot' => $tierSnapshot,
                'metadata' => $metadata,
            ]);

            Log::info('Booking facts captured', [
                'booking_id' => $booking->id,
                'event_id' => $event->id,
                'facts_id' => $bookingFacts->id,
            ]);

            return $bookingFacts;
        });
    }

    /**
     * Capture event data snapshot
     */
    private function captureEventSnapshot(Event $event): array
    {
        return [
            'id' => $event->id,
            'name' => $event->name,
            'description' => $event->description,
            'event_date' => $event->event_date?->toISOString(),
            'event_time' => $event->event_time,
            'venue' => $event->venue,
            'capacity' => $event->capacity,
            'status' => $event->status,
            'terms_and_conditions' => $event->terms_and_conditions,
            'created_at' => $event->created_at?->toISOString(),
            'published_at' => $event->published_at?->toISOString(),
            'snapshot_version' => '1.0',
        ];
    }

    /**
     * Capture ticket type data snapshot
     */
    private function captureTicketTypeSnapshot(EventTicketType $ticketType): array
    {
        return [
            'id' => $ticketType->id,
            'name' => $ticketType->name,
            'description' => $ticketType->description,
            'price' => $ticketType->price,
            'capacity' => $ticketType->capacity,
            'max_per_booking' => $ticketType->max_per_booking,
            'sale_start_date' => $ticketType->sale_start_date?->toISOString(),
            'sale_end_date' => $ticketType->sale_end_date?->toISOString(),
            'is_active' => $ticketType->is_active,
            'snapshot_version' => '1.0',
        ];
    }

    /**
     * Capture artist/group data snapshot
     */
    private function captureArtistSnapshot(Event $event): ?array
    {
        $artistEvents = $event->artistEvents()->with(['artist', 'group'])->get();

        if ($artistEvents->isEmpty()) {
            return null;
        }

        $artists = [];
        $groups = [];

        foreach ($artistEvents as $artistEvent) {
            if ($artistEvent->artist) {
                $artists[] = [
                    'id' => $artistEvent->artist->id,
                    'name' => $artistEvent->artist->name,
                    'stage_name' => $artistEvent->artist->stage_name,
                    'role' => $artistEvent->role,
                    'performance_order' => $artistEvent->performance_order,
                ];
            }

            if ($artistEvent->group) {
                $groups[] = [
                    'id' => $artistEvent->group->id,
                    'name' => $artistEvent->group->name,
                    'role' => $artistEvent->role,
                    'performance_order' => $artistEvent->performance_order,
                ];
            }
        }

        return [
            'artists' => $artists,
            'groups' => $groups,
            'snapshot_version' => '1.0',
        ];
    }

    /**
     * Capture pricing data snapshot
     */
    private function capturePricingSnapshot(Booking $booking, EventTicketType $ticketType): array
    {
        return [
            'base_price' => $ticketType->price,
            'quantity' => $booking->quantity,
            'subtotal' => $booking->subtotal,
            'discount_amount' => $booking->discount_amount ?? 0,
            'total_amount' => $booking->total_amount,
            'currency' => $booking->currency ?? 'USD',
            'discount_type' => $booking->discount_type,
            'discount_reason' => $booking->discount_reason,
            'snapshot_version' => '1.0',
        ];
    }

    /**
     * Capture customer tier data snapshot
     */
    private function captureTierSnapshot(Booking $booking): ?array
    {
        if (! $booking->customerTier) {
            return null;
        }

        $customerTier = $booking->customerTier;
        $fanTier = $customerTier->fanTier;

        return [
            'customer_tier_id' => $customerTier->id,
            'fan_tier_id' => $fanTier->id,
            'tier_name' => $fanTier->name,
            'tier_level' => $fanTier->level,
            'current_points' => $customerTier->current_points,
            'lifetime_points' => $customerTier->lifetime_points,
            'tier_achieved_at' => $customerTier->tier_achieved_at?->toISOString(),
            'early_access_hours' => $fanTier->early_access_hours,
            'discount_percentage' => $fanTier->discount_percentage,
            'benefits' => $fanTier->benefits,
            'snapshot_version' => '1.0',
        ];
    }

    /**
     * Create booking metadata
     */
    private function createMetadata(Booking $booking): array
    {
        return [
            'booking_reference' => $booking->booking_reference,
            'customer_id' => $booking->customer_id,
            'attendee_count' => $booking->bookingAttendees->count(),
            'booking_channel' => $booking->booking_channel ?? 'web',
            'user_agent' => request()->header('User-Agent'),
            'ip_address' => request()->ip(),
            'session_id' => session()->getId(),
            'captured_at' => now()->toISOString(),
            'capture_version' => '1.0',
        ];
    }

    /**
     * Retrieve booking facts for a booking
     */
    /**
     * Analytics-style fact rows for lifecycle events (payment_confirmed,
     * ticket_issued, ...). The callers in BookingService/TicketService were
     * written against this method but it never existed (L-10 root cause:
     * the payment callback fataled with "call to undefined method", so
     * bookings stayed pending forever). Facts are additive snapshots stored
     * on the booking's metadata rows; getBookingFacts() keeps returning the
     * ORIGINAL capture row only.
     */
    public function createAnalyticsFact(string $factType, Booking $booking, array $data = []): BookingFacts
    {
        return BookingFacts::create([
            'booking_id' => $booking->id,
            // snapshot columns are NOT NULL json — an analytics fact carries
            // no new snapshot, so empty objects
            'event_snapshot' => [],
            'ticket_type_snapshot' => [],
            'artist_snapshot' => [],
            'pricing_snapshot' => [],
            'tier_snapshot' => [],
            'metadata' => array_merge([
                'analytics_fact' => $factType,
                'captured_at' => now()->toIso8601String(),
            ], $data),
        ]);
    }

    public function getBookingFacts(int $bookingId): ?BookingFacts
    {
        return BookingFacts::where('booking_id', $bookingId)->first();
    }

    /**
     * Get original event data from facts
     */
    public function getOriginalEventData(int $bookingId): ?array
    {
        $facts = $this->getBookingFacts($bookingId);

        return $facts?->getEventData();
    }

    /**
     * Get original pricing data from facts
     */
    public function getOriginalPricingData(int $bookingId): ?array
    {
        $facts = $this->getBookingFacts($bookingId);

        return $facts?->getPricingData();
    }

    /**
     * Compare current data with booking-time facts
     */
    public function compareWithCurrentData(int $bookingId): array
    {
        $facts = $this->getBookingFacts($bookingId);
        if (! $facts) {
            return ['error' => 'No booking facts found'];
        }

        $booking = Booking::with(['event', 'eventTicketType'])->find($bookingId);
        if (! $booking) {
            return ['error' => 'Booking not found'];
        }

        $comparison = [
            'booking_id' => $bookingId,
            'facts_captured_at' => $facts->created_at,
            'comparison_performed_at' => now(),
            'changes' => [],
        ];

        // Compare event data
        $currentEvent = $booking->event;
        $originalEvent = $facts->getEventData();

        if ($currentEvent->name !== $originalEvent['name']) {
            $comparison['changes']['event_name'] = [
                'original' => $originalEvent['name'],
                'current' => $currentEvent->name,
            ];
        }

        if ($currentEvent->capacity !== $originalEvent['capacity']) {
            $comparison['changes']['event_capacity'] = [
                'original' => $originalEvent['capacity'],
                'current' => $currentEvent->capacity,
            ];
        }

        // Compare ticket type data
        $currentTicketType = $booking->eventTicketType;
        $originalTicketType = $facts->getTicketTypeData();

        if ($currentTicketType->price !== $originalTicketType['price']) {
            $comparison['changes']['ticket_price'] = [
                'original' => $originalTicketType['price'],
                'current' => $currentTicketType->price,
            ];
        }

        return $comparison;
    }

    /**
     * Get facts statistics for reporting
     */
    public function getFactsStatistics(): array
    {
        return [
            'total_facts_records' => BookingFacts::count(),
            'facts_by_date' => BookingFacts::selectRaw('DATE(created_at) as date, COUNT(*) as count')
                ->groupBy('date')
                ->orderBy('date', 'desc')
                ->limit(30)
                ->get()
                ->toArray(),
            'oldest_facts' => BookingFacts::orderBy('created_at')->first()?->created_at,
            'newest_facts' => BookingFacts::orderBy('created_at', 'desc')->first()?->created_at,
        ];
    }
}
