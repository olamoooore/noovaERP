<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_reference',
        'event_id',
        'customer_id',
        'buyer_name',
        'buyer_email',
        'buyer_phone',
        'total_amount',
        'original_amount',
        'currency',
        'state',
        'payment_reference',
        'consent_pdpa',
        'consent_marketing',
        'tier_benefits_applied',
        'fraud_risk_score',
        'requires_manual_review',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'original_amount' => 'decimal:2',
        'consent_pdpa' => 'boolean',
        'consent_marketing' => 'boolean',
        'tier_benefits_applied' => 'array',
        'fraud_risk_score' => 'float',
        'requires_manual_review' => 'boolean',
    ];

    /**
     * Get validation rules for buyer data
     */
    public static function getBuyerValidationRules(): array
    {
        return [
            'buyer_name' => ['required', 'string', 'max:255', 'min:2'],
            'buyer_email' => ['required', 'email', 'max:255'],
            'buyer_phone' => ['required', 'string', 'max:20', 'min:8', 'regex:/^[\+]?[0-9\-\(\)\s]+$/'],
            'consent_pdpa' => ['required', 'boolean', 'accepted'],
            'consent_marketing' => ['boolean'],
        ];
    }

    /**
     * Get validation rules for booking creation
     */
    public static function getBookingValidationRules(): array
    {
        return array_merge(self::getBuyerValidationRules(), [
            'event_id' => ['required', 'integer', 'exists:events,id'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'attendees' => ['required', 'array', 'min:1'],
            'attendees.*.attendee_name' => ['required', 'string', 'max:255', 'min:2'],
            'attendees.*.attendee_email' => ['nullable', 'email', 'max:255'],
            'attendees.*.attendee_phone' => ['nullable', 'string', 'max:20', 'regex:/^[\+]?[0-9\-\(\)\s]+$/'],
            'attendees.*.ticket_type_id' => ['required', 'integer', 'exists:event_ticket_types,id'],
            'attendees.*.encrypted_id_info' => ['nullable', 'string'],
        ]);
    }

    /**
     * Get the event that owns this booking
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Get the customer that owns this booking
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the attendees for this booking
     */
    public function attendees(): HasMany
    {
        return $this->hasMany(BookingAttendee::class);
    }

    /**
     * Get the tickets for this booking
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}