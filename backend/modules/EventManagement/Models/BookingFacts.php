<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BookingFacts Model
 *
 * Stores immutable snapshots of all relevant data at booking time.
 * This prevents analytics drift when master data changes after booking completion.
 *
 * @property int $id
 * @property int $booking_id
 * @property array $event_snapshot
 * @property array $ticket_type_snapshot
 * @property array|null $artist_snapshot
 * @property array $pricing_snapshot
 * @property array|null $tier_snapshot
 * @property array|null $metadata
 * @property \Carbon\Carbon $created_at
 */
class BookingFacts extends Model
{
    protected $table = 'booking_facts';

    public $timestamps = false; // Only created_at, no updated_at for immutability

    protected $fillable = [
        'booking_id',
        'event_snapshot',
        'ticket_type_snapshot',
        'artist_snapshot',
        'pricing_snapshot',
        'tier_snapshot',
        'metadata',
    ];

    protected $casts = [
        'event_snapshot' => 'array',
        'ticket_type_snapshot' => 'array',
        'artist_snapshot' => 'array',
        'pricing_snapshot' => 'array',
        'tier_snapshot' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Relationship to the booking
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Get event data from snapshot
     */
    public function getEventData(): array
    {
        return $this->event_snapshot ?? [];
    }

    /**
     * Get ticket type data from snapshot
     */
    public function getTicketTypeData(): array
    {
        return $this->ticket_type_snapshot ?? [];
    }

    /**
     * Get artist data from snapshot
     */
    public function getArtistData(): ?array
    {
        return $this->artist_snapshot;
    }

    /**
     * Get pricing data from snapshot
     */
    public function getPricingData(): array
    {
        return $this->pricing_snapshot ?? [];
    }

    /**
     * Get tier data from snapshot
     */
    public function getTierData(): ?array
    {
        return $this->tier_snapshot;
    }

    /**
     * Get the original event name at booking time
     */
    public function getOriginalEventName(): ?string
    {
        return $this->event_snapshot['name'] ?? null;
    }

    /**
     * Get the original ticket price at booking time
     */
    public function getOriginalTicketPrice(): ?float
    {
        return $this->pricing_snapshot['base_price'] ?? null;
    }

    /**
     * Get the original capacity at booking time
     */
    public function getOriginalCapacity(): ?int
    {
        return $this->event_snapshot['capacity'] ?? null;
    }

    /**
     * Check if customer had tier benefits at booking time
     */
    public function hadTierBenefits(): bool
    {
        return ! empty($this->tier_snapshot);
    }

    /**
     * Get tier level at booking time
     */
    public function getOriginalTierLevel(): ?string
    {
        return $this->tier_snapshot['tier_level'] ?? null;
    }

    /**
     * Prevent updates to maintain immutability
     */
    public function update(array $attributes = [], array $options = [])
    {
        throw new \Exception('BookingFacts records are immutable and cannot be updated');
    }

    /**
     * Prevent deletion to maintain audit trail
     */
    public function delete()
    {
        throw new \Exception('BookingFacts records cannot be deleted to maintain audit trail');
    }
}
