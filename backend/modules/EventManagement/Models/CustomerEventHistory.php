<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\EventManagement\Traits\HasTenantScope;

/**
 * CustomerEventHistory Model
 * 
 * Tracks customer interactions with events for analytics and personalization.
 * 
 * @property int $id
 * @property string $tenant_id
 * @property string $customer_id
 * @property int $event_id
 * @property int|null $booking_id
 * @property string $interaction_type
 * @property array|null $interaction_data
 * @property \Carbon\Carbon $interaction_at
 * @property string|null $source
 * @property string|null $user_agent
 * @property string|null $ip_address
 * @property array|null $metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 */
class CustomerEventHistory extends Model
{
    use SoftDeletes, HasTenantScope;

    protected $table = 'customer_event_history';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'event_id',
        'booking_id',
        'interaction_type',
        'interaction_data',
        'interaction_at',
        'source',
        'user_agent',
        'ip_address',
        'metadata',
    ];

    protected $casts = [
        'interaction_data' => 'array',
        'interaction_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $dates = [
        'interaction_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * Get the event associated with this history entry
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Get the booking associated with this history entry
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Scope for specific interaction types
     */
    public function scopeByInteractionType($query, string $type)
    {
        return $query->where('interaction_type', $type);
    }

    /**
     * Scope for specific customer
     */
    public function scopeForCustomer($query, string $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * Scope for specific event
     */
    public function scopeForEvent($query, int $eventId)
    {
        return $query->where('event_id', $eventId);
    }

    /**
     * Scope for date range
     */
    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('interaction_at', [$startDate, $endDate]);
    }

    /**
     * Get interaction types
     */
    public static function getInteractionTypes(): array
    {
        return [
            'viewed' => 'Event Viewed',
            'bookmarked' => 'Event Bookmarked',
            'booked' => 'Event Booked',
            'attended' => 'Event Attended',
            'cancelled' => 'Booking Cancelled',
            'shared' => 'Event Shared',
            'reviewed' => 'Event Reviewed',
            'recommended' => 'Event Recommended',
        ];
    }

    /**
     * Log a customer interaction
     */
    public static function logInteraction(
        string $tenantId,
        string $customerId,
        int $eventId,
        string $interactionType,
        ?int $bookingId = null,
        ?array $interactionData = null,
        ?array $metadata = null
    ): self {
        return self::create([
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'event_id' => $eventId,
            'booking_id' => $bookingId,
            'interaction_type' => $interactionType,
            'interaction_data' => $interactionData,
            'interaction_at' => now(),
            'source' => request()->header('User-Agent') ? 'web' : 'api',
            'user_agent' => request()->header('User-Agent'),
            'ip_address' => request()->ip(),
            'metadata' => $metadata,
        ]);
    }
}