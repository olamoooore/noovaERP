<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ticket extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_code',
        'booking_id',
        'attendee_id',
        'qr_payload',
        'status',
        'issued_at',
        'revoked_at',
        'used_at',
        'checked_in_at',
        'checked_in_counter_id',
        'checked_in_zone',
        'checked_in_by',
        'override_reason',
        'is_override',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'revoked_at' => 'datetime',
        'used_at' => 'datetime',
        'checked_in_at' => 'datetime',
        'is_override' => 'boolean',
    ];

    /**
     * Get the booking that owns this ticket
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Get the attendee that owns this ticket
     */
    public function attendee(): BelongsTo
    {
        return $this->belongsTo(BookingAttendee::class, 'attendee_id');
    }

    /**
     * Check if ticket is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if ticket is revoked
     */
    public function isRevoked(): bool
    {
        return $this->status === 'revoked';
    }

    /**
     * Check if ticket is used
     */
    public function isUsed(): bool
    {
        return $this->status === 'used';
    }

    /**
     * Check if ticket is checked in
     */
    public function isCheckedIn(): bool
    {
        return $this->status === 'checked_in';
    }

    /**
     * Check if ticket was checked in via override
     */
    public function isOverride(): bool
    {
        return $this->is_override === true;
    }

    /**
     * Get the check-in counter relationship
     */
    public function checkInCounter(): BelongsTo
    {
        return $this->belongsTo(CheckInCounter::class, 'checked_in_counter_id');
    }
}