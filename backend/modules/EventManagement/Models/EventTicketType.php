<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventTicketType extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'name',
        'price',
        'capacity',
        'available_capacity',
        'description',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'capacity' => 'integer',
        'available_capacity' => 'integer',
    ];

    /**
     * Get validation rules for ticket type creation
     */
    public static function getValidationRules(): array
    {
        return [
            'event_id' => ['required', 'integer', 'exists:events,id'],
            'name' => ['required', 'string', 'max:255', 'min:2'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'capacity' => ['required', 'integer', 'min:1', 'max:100000'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get validation rules for updating ticket type
     */
    public static function getUpdateValidationRules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255', 'min:2'],
            'price' => ['sometimes', 'numeric', 'min:0', 'max:999999.99'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get the event that owns this ticket type
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Get the booking attendees for this ticket type
     */
    public function bookingAttendees(): HasMany
    {
        return $this->hasMany(BookingAttendee::class, 'ticket_type_id');
    }

    /**
     * Check if ticket type is available
     */
    public function isAvailable(): bool
    {
        return $this->available_capacity > 0;
    }

    /**
     * Check if ticket type can accommodate requested quantity
     */
    public function canAccommodate(int $quantity): bool
    {
        return $this->available_capacity >= $quantity;
    }

    /**
     * Reserve capacity for a booking
     */
    public function reserveCapacity(int $quantity): bool
    {
        if (! $this->canAccommodate($quantity)) {
            return false;
        }

        $this->available_capacity -= $quantity;

        return $this->save();
    }

    /**
     * Release reserved capacity (e.g., when booking is cancelled)
     */
    public function releaseCapacity(int $quantity): bool
    {
        $newCapacity = $this->available_capacity + $quantity;

        // Don't exceed original capacity
        if ($newCapacity > $this->capacity) {
            $newCapacity = $this->capacity;
        }

        $this->available_capacity = $newCapacity;

        return $this->save();
    }

    /**
     * Get sold ticket count
     */
    public function getSoldCount(): int
    {
        return $this->capacity - $this->available_capacity;
    }

    /**
     * Get capacity utilization percentage
     */
    public function getUtilizationPercentage(): float
    {
        if ($this->capacity === 0) {
            return 0.0;
        }

        return round(($this->getSoldCount() / $this->capacity) * 100, 2);
    }

    /**
     * Check if ticket type is sold out
     */
    public function isSoldOut(): bool
    {
        return $this->available_capacity <= 0;
    }

    /**
     * Get formatted price with currency
     */
    public function getFormattedPrice(string $currency = 'USD'): string
    {
        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
            'KRW' => '₩',
            'SGD' => 'S$',
            'MYR' => 'RM',
            'THB' => '฿',
            'PHP' => '₱',
        ];

        $symbol = $symbols[$currency] ?? $currency;

        return $symbol.number_format($this->price, 2);
    }

    /**
     * Check if capacity update is valid
     */
    public function canUpdateCapacity(int $newCapacity): bool
    {
        $soldTickets = $this->getSoldCount();

        return $newCapacity >= $soldTickets;
    }

    /**
     * Update capacity safely
     */
    public function updateCapacity(int $newCapacity): bool
    {
        if (! $this->canUpdateCapacity($newCapacity)) {
            return false;
        }

        $soldTickets = $this->getSoldCount();
        $this->capacity = $newCapacity;
        $this->available_capacity = $newCapacity - $soldTickets;

        return $this->save();
    }

    /**
     * Get availability status
     */
    public function getAvailabilityStatus(): string
    {
        if ($this->isSoldOut()) {
            return 'sold_out';
        }

        $utilization = $this->getUtilizationPercentage();

        if ($utilization >= 90) {
            return 'limited';
        }

        if ($utilization >= 50) {
            return 'moderate';
        }

        return 'available';
    }
}
