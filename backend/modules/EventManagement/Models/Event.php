<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    use HasFactory;

    // Acquisition mode constants
    const MODE_BUY = 'buy';
    const MODE_BID = 'bid';

    protected $fillable = [
        'title',
        'description',
        'event_date',
        'venue',
        'total_capacity',
        'available_capacity',
        'status',
        'terms_conditions',
        'event_type_id',
        'ticket_acquisition_mode',
        'sale_start_date',
        'sale_end_date',
    ];

    protected $casts = [
        'event_date' => 'datetime',
        'total_capacity' => 'integer',
        'available_capacity' => 'integer',
        'sale_start_date' => 'datetime',
        'sale_end_date' => 'datetime',
    ];

    /**
     * Get the ticket types for this event
     */
    public function ticketTypes(): HasMany
    {
        return $this->hasMany(EventTicketType::class);
    }

    /**
     * Get the bookings for this event
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Get the event type that owns the event
     */
    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class, 'event_type_id');
    }

    /**
     * Get the bids for this event
     */
    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    /**
     * Check if event is published
     */
    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * Check if event is sold out
     */
    public function isSoldOut(): bool
    {
        return $this->status === 'sold_out' || $this->available_capacity <= 0;
    }

    /**
     * Check if event can accept new bookings
     */
    public function canAcceptBookings(): bool
    {
        return $this->isPublished() && !$this->isSoldOut() && $this->status !== 'cancelled';
    }
}