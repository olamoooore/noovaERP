<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EarlyAccessWindow extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'event_id',
        'fan_tier_id',
        'access_starts_at',
        'access_ends_at',
        'max_tickets_per_customer',
        'eligible_ticket_types',
        'is_active',
    ];

    protected $casts = [
        'access_starts_at' => 'datetime',
        'access_ends_at' => 'datetime',
        'eligible_ticket_types' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Get the event that owns this early access window.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Get the fan tier that owns this early access window.
     */
    public function fanTier(): BelongsTo
    {
        return $this->belongsTo(FanTier::class);
    }

    /**
     * Scope to filter by tenant.
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to get active windows only.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get currently open windows.
     */
    public function scopeCurrentlyOpen($query)
    {
        $now = now();

        return $query->where('access_starts_at', '<=', $now)
            ->where('access_ends_at', '>', $now);
    }

    /**
     * Scope to get upcoming windows.
     */
    public function scopeUpcoming($query)
    {
        return $query->where('access_starts_at', '>', now());
    }

    /**
     * Scope to filter by event.
     */
    public function scopeForEvent($query, int $eventId)
    {
        return $query->where('event_id', $eventId);
    }

    /**
     * Scope to filter by fan tier.
     */
    public function scopeForFanTier($query, int $fanTierId)
    {
        return $query->where('fan_tier_id', $fanTierId);
    }

    /**
     * Check if the early access window is currently open.
     */
    public function isCurrentlyOpen(): bool
    {
        $now = now();

        return $this->is_active
            && $this->access_starts_at <= $now
            && $this->access_ends_at > $now;
    }

    /**
     * Check if the early access window is upcoming.
     */
    public function isUpcoming(): bool
    {
        return $this->is_active && $this->access_starts_at > now();
    }

    /**
     * Check if the early access window has ended.
     */
    public function hasEnded(): bool
    {
        return $this->access_ends_at <= now();
    }

    /**
     * Check if a ticket type is eligible for this early access window.
     */
    public function isTicketTypeEligible(int $ticketTypeId): bool
    {
        if (empty($this->eligible_ticket_types)) {
            return true; // All ticket types eligible if none specified
        }

        return in_array($ticketTypeId, $this->eligible_ticket_types);
    }

    /**
     * Get the remaining time until the window opens (in seconds).
     */
    public function getTimeUntilOpen(): ?int
    {
        if ($this->access_starts_at <= now()) {
            return null; // Already open or past
        }

        return $this->access_starts_at->diffInSeconds(now());
    }

    /**
     * Get the remaining time until the window closes (in seconds).
     */
    public function getTimeUntilClose(): ?int
    {
        if ($this->access_ends_at <= now()) {
            return null; // Already closed
        }

        return $this->access_ends_at->diffInSeconds(now());
    }
}
