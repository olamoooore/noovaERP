<?php

namespace Modules\EventManagement\Services;

use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\Booking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * CapacityReservationService - Task 3.2
 * 
 * Implements atomic capacity reservation and release with optimistic locking.
 * Provides real-time capacity availability calculations with concurrency protection.
 * 
 * Requirements: 19.1, 19.2, 19.3, 19.5
 */
class CapacityReservationService
{
    protected AuditService $auditService;
    
    // Default reservation expiration time (15 minutes)
    private const DEFAULT_RESERVATION_MINUTES = 15;
    
    // Maximum retry attempts for optimistic locking
    private const MAX_RETRY_ATTEMPTS = 3;
    
    // Redis key prefixes for capacity tracking
    private const REDIS_CAPACITY_PREFIX = 'capacity:event:';
    private const REDIS_RESERVATION_PREFIX = 'reservation:';

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    /**
     * Create a capacity reservation with atomic operations and optimistic locking.
     * 
     * @param int $eventId Event to reserve capacity for
     * @param int $quantity Number of tickets to reserve
     * @param array $options Reservation options (booking_id, ticket_type_id, customer_session_id, etc.)
     * @return array Reservation result with token and expiration
     * 
     * Requirements: 19.1, 19.2, 19.3
     */
    public function createReservation(int $eventId, int $quantity, array $options = []): array
    {
        $retryCount = 0;
        
        while ($retryCount < self::MAX_RETRY_ATTEMPTS) {
            try {
                return DB::transaction(function () use ($eventId, $quantity, $options) {
                    // Step 1: Lock event row for capacity checking
                    $event = Event::lockForUpdate()->findOrFail($eventId);
                    
                    // Step 2: Validate event can accept reservations
                    if (!$this->canEventAcceptReservations($event)) {
                        throw new \InvalidArgumentException('Event cannot accept new reservations');
                    }
                    
                    // Step 3: Calculate current available capacity
                    $availableCapacity = $this->calculateRealTimeAvailableCapacity($event);
                    
                    if ($availableCapacity < $quantity) {
                        throw new \InvalidArgumentException(
                            "Insufficient capacity. Available: {$availableCapacity}, Requested: {$quantity}"
                        );
                    }
                    
                    // Step 4: Generate unique reservation token
                    $reservationToken = $this->generateReservationToken();
                    $expiresAt = Carbon::now()->addMinutes(
                        $options['reservation_minutes'] ?? self::DEFAULT_RESERVATION_MINUTES
                    );
                    
                    // Step 5: Create reservation record
                    $reservationId = DB::table('capacity_reservations')->insertGetId([
                        'event_id' => $eventId,
                        'booking_id' => $options['booking_id'] ?? null,
                        'ticket_type_id' => $options['ticket_type_id'] ?? null,
                        'quantity' => $quantity,
                        'reservation_token' => $reservationToken,
                        'status' => 'active',
                        'reservation_expires_at' => $expiresAt,
                        'customer_session_id' => $options['customer_session_id'] ?? null,
                        'ip_address' => $options['ip_address'] ?? null,
                        'reservation_metadata' => json_encode($options['metadata'] ?? []),
                        'created_at' => Carbon::now(),
                    ]);
                    
                    // Step 6: Update event reserved capacity with optimistic locking
                    $newReservedCapacity = $event->reserved_capacity + $quantity;
                    $newCapacityVersion = $event->capacity_version + 1;
                    
                    $updated = DB::table('events')
                        ->where('id', $eventId)
                        ->where('capacity_version', $event->capacity_version) // Optimistic lock check
                        ->update([
                            'reserved_capacity' => $newReservedCapacity,
                            'capacity_version' => $newCapacityVersion,
                            'capacity_last_updated' => Carbon::now(),
                        ]);
                    
                    if ($updated === 0) {
                        // Optimistic lock failed - capacity was modified by another process
                        throw new \Exception('Capacity was modified by another process, retrying...');
                    }
                    
                    // Step 7: Update Redis cache for real-time capacity tracking
                    $this->updateCapacityCache($eventId, $event->capacity - $newReservedCapacity - $event->confirmed_capacity);
                    
                    // Step 8: Log capacity audit trail
                    $this->logCapacityAudit($eventId, 'reservation_created', $quantity, [
                        'capacity_before' => $event->capacity - $event->reserved_capacity - $event->confirmed_capacity,
                        'capacity_after' => $event->capacity - $newReservedCapacity - $event->confirmed_capacity,
                        'reserved_before' => $event->reserved_capacity,
                        'reserved_after' => $newReservedCapacity,
                        'related_reservation_id' => $reservationId,
                        'actor_type' => 'system',
                        'notes' => "Created reservation for {$quantity} tickets",
                    ]);
                    
                    return [
                        'success' => true,
                        'reservation_id' => $reservationId,
                        'reservation_token' => $reservationToken,
                        'quantity' => $quantity,
                        'expires_at' => $expiresAt->toISOString(),
                        'available_capacity_after' => $event->capacity - $newReservedCapacity - $event->confirmed_capacity,
                    ];
                });
                
            } catch (\Exception $e) {
                $retryCount++;
                
                if ($retryCount >= self::MAX_RETRY_ATTEMPTS) {
                    throw $e;
                }
                
                // Brief delay before retry to reduce contention
                usleep(rand(10000, 50000)); // 10-50ms random delay
            }
        }
        
        throw new \Exception('Failed to create reservation after maximum retry attempts');
    }

    /**
     * Confirm a reservation by converting it to confirmed capacity.
     * 
     * @param string $reservationToken Unique reservation token
     * @param int $bookingId Booking ID to associate with confirmed reservation
     * @return array Confirmation result
     * 
     * Requirements: 19.1, 19.2
     */
    public function confirmReservation(string $reservationToken, int $bookingId): array
    {
        return DB::transaction(function () use ($reservationToken, $bookingId) {
            // Step 1: Find and lock the reservation
            $reservation = DB::table('capacity_reservations')
                ->where('reservation_token', $reservationToken)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            
            if (!$reservation) {
                throw new \InvalidArgumentException('Reservation not found or already processed');
            }
            
            // Step 2: Check if reservation has expired
            if (Carbon::parse($reservation->reservation_expires_at)->isPast()) {
                // Mark as expired and release capacity
                $this->expireReservation($reservation->id);
                throw new \InvalidArgumentException('Reservation has expired');
            }
            
            // Step 3: Lock event for capacity updates
            $event = Event::lockForUpdate()->findOrFail($reservation->event_id);
            
            // Step 4: Update reservation status
            DB::table('capacity_reservations')
                ->where('id', $reservation->id)
                ->update([
                    'status' => 'confirmed',
                    'booking_id' => $bookingId,
                    'confirmed_at' => Carbon::now(),
                ]);
            
            // Step 5: Move capacity from reserved to confirmed
            $newReservedCapacity = $event->reserved_capacity - $reservation->quantity;
            $newConfirmedCapacity = $event->confirmed_capacity + $reservation->quantity;
            $newCapacityVersion = $event->capacity_version + 1;
            
            DB::table('events')
                ->where('id', $reservation->event_id)
                ->update([
                    'reserved_capacity' => $newReservedCapacity,
                    'confirmed_capacity' => $newConfirmedCapacity,
                    'capacity_version' => $newCapacityVersion,
                    'capacity_last_updated' => Carbon::now(),
                ]);
            
            // Step 6: Update Redis cache
            $availableCapacity = $event->capacity - $newReservedCapacity - $newConfirmedCapacity;
            $this->updateCapacityCache($reservation->event_id, $availableCapacity);
            
            // Step 7: Log capacity audit trail
            $this->logCapacityAudit($reservation->event_id, 'reservation_confirmed', 0, [
                'capacity_before' => $event->capacity - $event->reserved_capacity - $event->confirmed_capacity,
                'capacity_after' => $availableCapacity,
                'reserved_before' => $event->reserved_capacity,
                'reserved_after' => $newReservedCapacity,
                'related_reservation_id' => $reservation->id,
                'related_booking_id' => $bookingId,
                'actor_type' => 'system',
                'notes' => "Confirmed reservation for {$reservation->quantity} tickets",
            ]);
            
            return [
                'success' => true,
                'reservation_id' => $reservation->id,
                'booking_id' => $bookingId,
                'quantity' => $reservation->quantity,
                'available_capacity_after' => $availableCapacity,
            ];
        });
    }

    /**
     * Release a reservation and return capacity to available pool.
     * 
     * @param string $reservationToken Unique reservation token
     * @param string $reason Reason for releasing the reservation
     * @return array Release result
     * 
     * Requirements: 19.1, 19.2
     */
    public function releaseReservation(string $reservationToken, string $reason = 'Manual release'): array
    {
        return DB::transaction(function () use ($reservationToken, $reason) {
            // Step 1: Find and lock the reservation
            $reservation = DB::table('capacity_reservations')
                ->where('reservation_token', $reservationToken)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            
            if (!$reservation) {
                throw new \InvalidArgumentException('Reservation not found or already processed');
            }
            
            // Step 2: Lock event for capacity updates
            $event = Event::lockForUpdate()->findOrFail($reservation->event_id);
            
            // Step 3: Update reservation status
            DB::table('capacity_reservations')
                ->where('id', $reservation->id)
                ->update([
                    'status' => 'cancelled',
                    'expired_at' => Carbon::now(),
                ]);
            
            // Step 4: Release reserved capacity
            $newReservedCapacity = $event->reserved_capacity - $reservation->quantity;
            $newCapacityVersion = $event->capacity_version + 1;
            
            DB::table('events')
                ->where('id', $reservation->event_id)
                ->update([
                    'reserved_capacity' => $newReservedCapacity,
                    'capacity_version' => $newCapacityVersion,
                    'capacity_last_updated' => Carbon::now(),
                ]);
            
            // Step 5: Update Redis cache
            $availableCapacity = $event->capacity - $newReservedCapacity - $event->confirmed_capacity;
            $this->updateCapacityCache($reservation->event_id, $availableCapacity);
            
            // Step 6: Log capacity audit trail
            $this->logCapacityAudit($reservation->event_id, 'reservation_cancelled', -$reservation->quantity, [
                'capacity_before' => $event->capacity - $event->reserved_capacity - $event->confirmed_capacity,
                'capacity_after' => $availableCapacity,
                'reserved_before' => $event->reserved_capacity,
                'reserved_after' => $newReservedCapacity,
                'related_reservation_id' => $reservation->id,
                'actor_type' => 'system',
                'notes' => "Released reservation: {$reason}",
            ]);
            
            return [
                'success' => true,
                'reservation_id' => $reservation->id,
                'quantity_released' => $reservation->quantity,
                'available_capacity_after' => $availableCapacity,
                'reason' => $reason,
            ];
        });
    }

    /**
     * Calculate real-time available capacity for an event.
     * 
     * @param Event $event Event to calculate capacity for
     * @return int Available capacity
     * 
     * Requirements: 19.5
     */
    public function calculateRealTimeAvailableCapacity(Event $event): int
    {
        // Check Redis cache first for performance
        $cachedCapacity = Redis::get(self::REDIS_CAPACITY_PREFIX . $event->id);
        if ($cachedCapacity !== null) {
            return (int) $cachedCapacity;
        }
        
        // Calculate from database if not cached
        $event->refresh(); // Ensure we have latest data
        
        $availableCapacity = $event->capacity - $event->reserved_capacity - $event->confirmed_capacity;
        
        // Update cache
        $this->updateCapacityCache($event->id, $availableCapacity);
        
        return max(0, $availableCapacity);
    }

    /**
     * Get reservation details by token.
     * 
     * @param string $reservationToken Unique reservation token
     * @return array|null Reservation details or null if not found
     */
    public function getReservation(string $reservationToken): ?array
    {
        $reservation = DB::table('capacity_reservations')
            ->where('reservation_token', $reservationToken)
            ->first();
        
        if (!$reservation) {
            return null;
        }
        
        return [
            'id' => $reservation->id,
            'event_id' => $reservation->event_id,
            'booking_id' => $reservation->booking_id,
            'quantity' => $reservation->quantity,
            'status' => $reservation->status,
            'expires_at' => $reservation->reservation_expires_at,
            'is_expired' => Carbon::parse($reservation->reservation_expires_at)->isPast(),
            'metadata' => json_decode($reservation->reservation_metadata, true),
        ];
    }

    /**
     * Expire a reservation and release its capacity.
     * 
     * @param int $reservationId Reservation ID to expire
     * @return bool Success status
     * 
     * Requirements: 19.4
     */
    public function expireReservation(int $reservationId): bool
    {
        return DB::transaction(function () use ($reservationId) {
            // Step 1: Find and lock the reservation
            $reservation = DB::table('capacity_reservations')
                ->where('id', $reservationId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            
            if (!$reservation) {
                return false; // Already processed or not found
            }
            
            // Step 2: Lock event for capacity updates
            $event = Event::lockForUpdate()->findOrFail($reservation->event_id);
            
            // Step 3: Update reservation status
            DB::table('capacity_reservations')
                ->where('id', $reservationId)
                ->update([
                    'status' => 'expired',
                    'expired_at' => Carbon::now(),
                ]);
            
            // Step 4: Release reserved capacity
            $newReservedCapacity = $event->reserved_capacity - $reservation->quantity;
            $newCapacityVersion = $event->capacity_version + 1;
            
            DB::table('events')
                ->where('id', $reservation->event_id)
                ->update([
                    'reserved_capacity' => $newReservedCapacity,
                    'capacity_version' => $newCapacityVersion,
                    'capacity_last_updated' => Carbon::now(),
                ]);
            
            // Step 5: Update Redis cache
            $availableCapacity = $event->capacity - $newReservedCapacity - $event->confirmed_capacity;
            $this->updateCapacityCache($reservation->event_id, $availableCapacity);
            
            // Step 6: Log capacity audit trail
            $this->logCapacityAudit($reservation->event_id, 'reservation_expired', -$reservation->quantity, [
                'capacity_before' => $event->capacity - $event->reserved_capacity - $event->confirmed_capacity,
                'capacity_after' => $availableCapacity,
                'reserved_before' => $event->reserved_capacity,
                'reserved_after' => $newReservedCapacity,
                'related_reservation_id' => $reservationId,
                'actor_type' => 'system',
                'notes' => "Reservation expired automatically",
            ]);
            
            return true;
        });
    }

    /**
     * Get expired reservations that need cleanup.
     * 
     * @param int $batchSize Number of reservations to return
     * @return array List of expired reservation IDs
     * 
     * Requirements: 19.4
     */
    public function getExpiredReservations(int $batchSize = 100): array
    {
        return DB::table('capacity_reservations')
            ->where('status', 'active')
            ->where('reservation_expires_at', '<', Carbon::now())
            ->limit($batchSize)
            ->pluck('id')
            ->toArray();
    }

    /**
     * Perform capacity reconciliation for an event.
     * 
     * @param int $eventId Event ID to reconcile
     * @return array Reconciliation results
     * 
     * Requirements: 19.5
     */
    public function reconcileEventCapacity(int $eventId): array
    {
        return DB::transaction(function () use ($eventId) {
            $event = Event::lockForUpdate()->findOrFail($eventId);
            
            // Count active reservations
            $activeReservations = DB::table('capacity_reservations')
                ->where('event_id', $eventId)
                ->where('status', 'active')
                ->where('reservation_expires_at', '>', Carbon::now())
                ->sum('quantity');
            
            // Count confirmed bookings
            $confirmedBookings = DB::table('capacity_reservations')
                ->where('event_id', $eventId)
                ->where('status', 'confirmed')
                ->sum('quantity');
            
            // Calculate expected vs actual
            $expectedAvailable = $event->capacity - $event->reserved_capacity - $event->confirmed_capacity;
            $actualReserved = $activeReservations;
            $actualConfirmed = $confirmedBookings;
            $actualAvailable = $event->capacity - $actualReserved - $actualConfirmed;
            
            $reservedDiscrepancy = $event->reserved_capacity - $actualReserved;
            $confirmedDiscrepancy = $event->confirmed_capacity - $actualConfirmed;
            $availableDiscrepancy = $expectedAvailable - $actualAvailable;
            
            $status = 'passed';
            if (abs($reservedDiscrepancy) > 0 || abs($confirmedDiscrepancy) > 0) {
                $status = abs($reservedDiscrepancy) > 10 || abs($confirmedDiscrepancy) > 10 ? 'critical' : 'warning';
            }
            
            // Log reconciliation results
            DB::table('capacity_reconciliation_log')->insert([
                'event_id' => $eventId,
                'expected_available' => $expectedAvailable,
                'actual_available' => $actualAvailable,
                'reserved_count' => $actualReserved,
                'confirmed_count' => $actualConfirmed,
                'discrepancy' => $availableDiscrepancy,
                'status' => $status,
                'reconciliation_data' => json_encode([
                    'event_capacity' => $event->capacity,
                    'event_reserved' => $event->reserved_capacity,
                    'event_confirmed' => $event->confirmed_capacity,
                    'actual_reserved' => $actualReserved,
                    'actual_confirmed' => $actualConfirmed,
                    'reserved_discrepancy' => $reservedDiscrepancy,
                    'confirmed_discrepancy' => $confirmedDiscrepancy,
                ]),
                'reconciled_at' => Carbon::now(),
            ]);
            
            // Auto-correct minor discrepancies
            if ($status === 'warning' && abs($reservedDiscrepancy) <= 5 && abs($confirmedDiscrepancy) <= 5) {
                DB::table('events')
                    ->where('id', $eventId)
                    ->update([
                        'reserved_capacity' => $actualReserved,
                        'confirmed_capacity' => $actualConfirmed,
                        'capacity_version' => $event->capacity_version + 1,
                        'capacity_last_updated' => Carbon::now(),
                    ]);
                
                $this->updateCapacityCache($eventId, $actualAvailable);
                
                $status = 'corrected';
            }
            
            return [
                'status' => $status,
                'event_id' => $eventId,
                'expected_available' => $expectedAvailable,
                'actual_available' => $actualAvailable,
                'reserved_discrepancy' => $reservedDiscrepancy,
                'confirmed_discrepancy' => $confirmedDiscrepancy,
                'available_discrepancy' => $availableDiscrepancy,
                'auto_corrected' => $status === 'corrected',
            ];
        });
    }

    /**
     * Check if an event can accept new reservations.
     * 
     * @param Event $event Event to check
     * @return bool Whether event can accept reservations
     */
    private function canEventAcceptReservations(Event $event): bool
    {
        return in_array($event->status, ['published', 'early_access']) && 
               $event->event_date > Carbon::now();
    }

    /**
     * Generate a unique reservation token.
     * 
     * @return string Unique reservation token
     */
    private function generateReservationToken(): string
    {
        do {
            $token = 'RSV_' . strtoupper(Str::random(16));
        } while (DB::table('capacity_reservations')->where('reservation_token', $token)->exists());
        
        return $token;
    }

    /**
     * Update capacity cache in Redis.
     * 
     * @param int $eventId Event ID
     * @param int $availableCapacity Available capacity
     */
    private function updateCapacityCache(int $eventId, int $availableCapacity): void
    {
        Redis::setex(
            self::REDIS_CAPACITY_PREFIX . $eventId,
            300, // 5 minutes TTL
            max(0, $availableCapacity)
        );
    }

    /**
     * Log capacity audit trail.
     * 
     * @param int $eventId Event ID
     * @param string $action Action performed
     * @param int $quantityChange Change in quantity
     * @param array $metadata Additional metadata
     */
    private function logCapacityAudit(int $eventId, string $action, int $quantityChange, array $metadata): void
    {
        DB::table('capacity_audit_log')->insert([
            'event_id' => $eventId,
            'action' => $action,
            'quantity_change' => $quantityChange,
            'capacity_before' => $metadata['capacity_before'],
            'capacity_after' => $metadata['capacity_after'],
            'reserved_before' => $metadata['reserved_before'],
            'reserved_after' => $metadata['reserved_after'],
            'related_reservation_id' => $metadata['related_reservation_id'] ?? null,
            'related_booking_id' => $metadata['related_booking_id'] ?? null,
            'actor_type' => $metadata['actor_type'],
            'actor_id' => $metadata['actor_id'] ?? null,
            'notes' => $metadata['notes'],
            'audit_metadata' => json_encode($metadata),
            'logged_at' => Carbon::now(),
        ]);
    }
}