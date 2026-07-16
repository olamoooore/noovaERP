<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingVelocityLog extends Model
{
    use HasFactory;

    public $timestamps = false; // Only has created_at

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'ip_address',
        'user_agent',
        'event_id',
        'tickets_requested',
        'action_type',
        'session_id',
        'request_metadata',
        'created_at',
    ];

    protected $casts = [
        'request_metadata' => 'array',
        'created_at' => 'datetime',
    ];

    // Action type constants
    public const ACTION_BOOKING_ATTEMPT = 'booking_attempt';

    public const ACTION_BOOKING_SUCCESS = 'booking_success';

    public const ACTION_BOOKING_FAILED = 'booking_failed';

    public const ACTION_BOOKING_CANCELLED = 'booking_cancelled';

    /**
     * Get the customer that performed this action.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the event this action was performed on.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Scope to filter by tenant.
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to filter by customer.
     */
    public function scopeForCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * Scope to filter by IP address.
     */
    public function scopeForIpAddress($query, string $ipAddress)
    {
        return $query->where('ip_address', $ipAddress);
    }

    /**
     * Scope to filter by action type.
     */
    public function scopeOfType($query, string $actionType)
    {
        return $query->where('action_type', $actionType);
    }

    /**
     * Scope to get logs within a time window.
     */
    public function scopeWithinTimeWindow($query, int $minutes)
    {
        return $query->where('created_at', '>=', now()->subMinutes($minutes));
    }

    /**
     * Scope to get logs within a date range.
     */
    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Get the anonymized IP address for privacy.
     */
    public function getAnonymizedIpAttribute(): string
    {
        $parts = explode('.', $this->ip_address);
        if (count($parts) === 4) {
            // IPv4 - mask last octet
            return $parts[0].'.'.$parts[1].'.'.$parts[2].'.xxx';
        }

        // IPv6 or other format - mask last part
        $parts = explode(':', $this->ip_address);
        if (count($parts) > 1) {
            $parts[count($parts) - 1] = 'xxxx';

            return implode(':', $parts);
        }

        return 'xxx.xxx.xxx.xxx';
    }

    /**
     * Check if this log indicates suspicious activity.
     */
    public function isSuspicious(): bool
    {
        // High number of tickets requested
        if ($this->tickets_requested > 10) {
            return true;
        }

        // Failed booking attempts might indicate bot activity
        if ($this->action_type === self::ACTION_BOOKING_FAILED) {
            return true;
        }

        return false;
    }
}
