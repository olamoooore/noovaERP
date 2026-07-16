<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\EventManagement\Services\DataEncryptionService;

class BookingAttendee extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'ticket_type_id',
        'attendee_name',
        'attendee_email',
        'attendee_phone',
        'encrypted_id_info',
    ];

    /**
     * The attributes that should be encrypted.
     */
    protected $encrypted = [
        'encrypted_id_info',
    ];

    /**
     * Get the encrypted_id_info attribute.
     */
    public function getEncryptedIdInfoAttribute($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $encryptionService = app(DataEncryptionService::class);

        return $encryptionService->decryptIdInformation($value);
    }

    /**
     * Set the encrypted_id_info attribute.
     */
    public function setEncryptedIdInfoAttribute($value): void
    {
        if (empty($value)) {
            $this->attributes['encrypted_id_info'] = null;

            return;
        }

        $encryptionService = app(DataEncryptionService::class);
        $this->attributes['encrypted_id_info'] = $encryptionService->encryptIdInformation($value);
    }

    /**
     * Set ID information securely
     */
    public function setIdInformation(?string $idInfo): void
    {
        $this->encrypted_id_info = $idInfo;
    }

    /**
     * Get decrypted ID information
     */
    public function getIdInformation(): ?string
    {
        return $this->encrypted_id_info;
    }

    /**
     * Check if attendee has ID information
     */
    public function hasIdInformation(): bool
    {
        return ! empty($this->getIdInformation());
    }

    /**
     * Get validation rules for attendee data
     */
    public static function getValidationRules(): array
    {
        return [
            'booking_id' => ['required', 'integer', 'exists:bookings,id'],
            'ticket_type_id' => ['required', 'integer', 'exists:event_ticket_types,id'],
            'attendee_name' => ['required', 'string', 'max:255', 'min:2'],
            'attendee_email' => ['nullable', 'email', 'max:255'],
            'attendee_phone' => ['nullable', 'string', 'max:20', 'regex:/^[\+]?[0-9\-\(\)\s]+$/'],
            'encrypted_id_info' => ['nullable', 'string'],
        ];
    }

    /**
     * Get validation rules for creating multiple attendees
     */
    public static function getBulkValidationRules(): array
    {
        return [
            'attendees' => ['required', 'array', 'min:1'],
            'attendees.*.ticket_type_id' => ['required', 'integer', 'exists:event_ticket_types,id'],
            'attendees.*.attendee_name' => ['required', 'string', 'max:255', 'min:2'],
            'attendees.*.attendee_email' => ['nullable', 'email', 'max:255'],
            'attendees.*.attendee_phone' => ['nullable', 'string', 'max:20', 'regex:/^[\+]?[0-9\-\(\)\s]+$/'],
            'attendees.*.encrypted_id_info' => ['nullable', 'string'],
        ];
    }

    /**
     * Validate attendee data based on event requirements
     */
    public function validateAgainstEventRequirements(): bool
    {
        $event = $this->booking->event;

        if (! tenancy()->initialized) {
            throw new \RuntimeException('Tenant context not initialized for BookingAttendee validation');
        }

        $eventSettings = EventSettings::forTenant((string) tenant()->id);

        // Check if ID is required but not provided
        if ($eventSettings && $eventSettings->requiresAttendeeId() && ! $this->hasIdInformation()) {
            return false;
        }

        // Check if attendee has required basic information
        if (! $this->hasRequiredInformation()) {
            return false;
        }

        return true;
    }

    /**
     * Validate that required attendee information is present
     */
    public function hasRequiredInformation(): bool
    {
        return ! empty($this->attendee_name);
    }

    /**
     * Check if attendee has contact information
     */
    public function hasContactInformation(): bool
    {
        return ! empty($this->attendee_email) || ! empty($this->attendee_phone);
    }

    /**
     * Get primary contact method
     */
    public function getPrimaryContact(): ?string
    {
        return $this->attendee_email ?: $this->attendee_phone;
    }

    /**
     * Check if attendee data is complete for the event requirements
     */
    public function isDataComplete(): bool
    {
        // Basic required information
        if (! $this->hasRequiredInformation()) {
            return false;
        }

        // Check if contact information is provided (at least one)
        if (! $this->hasContactInformation()) {
            return false;
        }

        return true;
    }

    /**
     * Get attendee display name with fallback
     */
    public function getDisplayName(): string
    {
        return $this->attendee_name ?: 'Unnamed Attendee';
    }

    /**
     * Check if this attendee belongs to a confirmed booking
     */
    public function belongsToConfirmedBooking(): bool
    {
        return $this->booking && in_array($this->booking->state, ['confirmed', 'booked']);
    }

    /**
     * Get the booking that owns this attendee
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Get the ticket type for this attendee
     */
    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(EventTicketType::class, 'ticket_type_id');
    }

    /**
     * Get the tickets for this attendee
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'attendee_id');
    }
}
