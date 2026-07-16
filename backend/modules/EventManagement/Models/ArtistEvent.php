<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\EventManagement\Traits\HasAuditTrail;
use Modules\EventManagement\Traits\HasTenantScope;

/**
 * ArtistEvent Model
 *
 * Represents the association between artists/groups and events,
 * including performance details, roles, and confirmation status.
 *
 * Requirements: 12.2, 12.3, 12.4
 */
class ArtistEvent extends Model
{
    use HasAuditTrail, HasFactory, HasTenantScope, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'event_id',
        'artist_id',
        'group_id',
        'role',
        'performance_order',
        'performance_duration',
        'setlist',
        'billing_name',
        'special_notes',
        'performance_fee',
        'technical_requirements',
        'status',
        'confirmed_at',
        'confirmed_by',
        'metadata',
    ];

    protected $casts = [
        'performance_duration' => 'datetime',
        'setlist' => 'array',
        'performance_fee' => 'decimal:2',
        'technical_requirements' => 'array',
        'confirmed_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $dates = [
        'deleted_at',
    ];

    /**
     * Get the event this association belongs to
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Get the artist this association belongs to (if applicable)
     */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    /**
     * Get the group this association belongs to (if applicable)
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Scope for confirmed performances
     */
    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    /**
     * Scope for completed performances
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope for main performers
     */
    public function scopeMainPerformers($query)
    {
        return $query->where('role', 'main');
    }

    /**
     * Scope for guest performers
     */
    public function scopeGuestPerformers($query)
    {
        return $query->whereIn('role', ['guest', 'special_guest']);
    }

    /**
     * Scope for artist performances
     */
    public function scopeArtistPerformances($query)
    {
        return $query->whereNotNull('artist_id');
    }

    /**
     * Scope for group performances
     */
    public function scopeGroupPerformances($query)
    {
        return $query->whereNotNull('group_id');
    }

    /**
     * Get the performer (artist or group)
     */
    public function getPerformer()
    {
        return $this->artist ?: $this->group;
    }

    /**
     * Get the performer name
     */
    public function getPerformerNameAttribute(): string
    {
        if ($this->artist) {
            return $this->artist->display_name;
        }

        if ($this->group) {
            return $this->group->display_name;
        }

        return 'Unknown Performer';
    }

    /**
     * Get the performer type
     */
    public function getPerformerTypeAttribute(): string
    {
        return $this->artist ? 'artist' : 'group';
    }

    /**
     * Get the billing name or default to performer name
     */
    public function getBillingDisplayNameAttribute(): string
    {
        return $this->billing_name ?: $this->performer_name;
    }

    /**
     * Check if performance is confirmed
     */
    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    /**
     * Check if performance is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if performance is cancelled
     */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Confirm the performance
     */
    public function confirm(?string $confirmedBy = null): void
    {
        $this->status = 'confirmed';
        $this->confirmed_at = now();
        $this->confirmed_by = $confirmedBy;
        $this->save();
    }

    /**
     * Cancel the performance
     */
    public function cancel(?string $reason = null): void
    {
        $this->status = 'cancelled';

        if ($reason) {
            $metadata = $this->metadata ?? [];
            $metadata['cancellation_reason'] = $reason;
            $metadata['cancelled_at'] = now()->toISOString();
            $this->metadata = $metadata;
        }

        $this->save();
    }

    /**
     * Mark performance as completed
     */
    public function markCompleted(): void
    {
        $this->status = 'completed';

        $metadata = $this->metadata ?? [];
        $metadata['completed_at'] = now()->toISOString();
        $this->metadata = $metadata;

        $this->save();
    }

    /**
     * Add song to setlist
     */
    public function addSongToSetlist(string $song, ?int $order = null): void
    {
        $setlist = $this->setlist ?? [];

        if ($order !== null) {
            // Insert at specific position
            array_splice($setlist, $order - 1, 0, $song);
        } else {
            // Add to end
            $setlist[] = $song;
        }

        $this->setlist = $setlist;
        $this->save();
    }

    /**
     * Remove song from setlist
     */
    public function removeSongFromSetlist(string $song): void
    {
        $setlist = $this->setlist ?? [];
        $this->setlist = array_values(array_filter($setlist, fn ($s) => $s !== $song));
        $this->save();
    }

    /**
     * Reorder setlist
     */
    public function reorderSetlist(array $newOrder): void
    {
        $this->setlist = $newOrder;
        $this->save();
    }

    /**
     * Get setlist duration estimate (assuming 3.5 minutes per song)
     */
    public function getEstimatedSetlistDuration(): int
    {
        $songCount = count($this->setlist ?? []);

        return $songCount * 3.5; // minutes
    }

    /**
     * Add technical requirement
     */
    public function addTechnicalRequirement(string $category, string $requirement): void
    {
        $requirements = $this->technical_requirements ?? [];

        if (! isset($requirements[$category])) {
            $requirements[$category] = [];
        }

        if (! in_array($requirement, $requirements[$category])) {
            $requirements[$category][] = $requirement;
        }

        $this->technical_requirements = $requirements;
        $this->save();
    }

    /**
     * Remove technical requirement
     */
    public function removeTechnicalRequirement(string $category, string $requirement): void
    {
        $requirements = $this->technical_requirements ?? [];

        if (isset($requirements[$category])) {
            $requirements[$category] = array_values(
                array_filter($requirements[$category], fn ($r) => $r !== $requirement)
            );

            if (empty($requirements[$category])) {
                unset($requirements[$category]);
            }
        }

        $this->technical_requirements = $requirements;
        $this->save();
    }

    /**
     * Get technical requirements by category
     */
    public function getTechnicalRequirements(?string $category = null): array
    {
        $requirements = $this->technical_requirements ?? [];

        if ($category) {
            return $requirements[$category] ?? [];
        }

        return $requirements;
    }

    /**
     * Calculate performance fee with role-based multiplier
     */
    public function calculatePerformanceFee(float $baseRate): float
    {
        $multiplier = match ($this->role) {
            'main' => 1.0,
            'guest' => 0.7,
            'collaboration' => 0.8,
            'special_guest' => 0.9,
            'opening_act' => 0.5,
            default => 1.0,
        };

        return $baseRate * $multiplier;
    }

    /**
     * Get role display name
     */
    public function getRoleDisplayNameAttribute(): string
    {
        return match ($this->role) {
            'main' => 'Main Performer',
            'guest' => 'Guest Performer',
            'collaboration' => 'Collaboration',
            'special_guest' => 'Special Guest',
            'opening_act' => 'Opening Act',
            default => ucfirst($this->role),
        };
    }

    /**
     * Check if performer has performed at this event before
     */
    public function isRepeatPerformer(): bool
    {
        $query = static::where('tenant_id', $this->tenant_id)
            ->where('event_id', '!=', $this->event_id)
            ->where('status', 'completed');

        if ($this->artist_id) {
            $query->where('artist_id', $this->artist_id);
        } else {
            $query->where('group_id', $this->group_id);
        }

        return $query->exists();
    }

    /**
     * Get performer's previous events
     */
    public function getPerformerPreviousEvents()
    {
        $query = static::with('event')
            ->where('tenant_id', $this->tenant_id)
            ->where('event_id', '!=', $this->event_id)
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc');

        if ($this->artist_id) {
            $query->where('artist_id', $this->artist_id);
        } else {
            $query->where('group_id', $this->group_id);
        }

        return $query->get();
    }

    /**
     * Get performance statistics for this performer
     */
    public function getPerformerStats(): array
    {
        $query = static::where('tenant_id', $this->tenant_id)
            ->where('status', 'completed');

        if ($this->artist_id) {
            $query->where('artist_id', $this->artist_id);
        } else {
            $query->where('group_id', $this->group_id);
        }

        $performances = $query->get();

        return [
            'total_performances' => $performances->count(),
            'main_performances' => $performances->where('role', 'main')->count(),
            'guest_performances' => $performances->whereIn('role', ['guest', 'special_guest'])->count(),
            'total_revenue' => $performances->sum('performance_fee'),
            'average_fee' => $performances->avg('performance_fee'),
        ];
    }
}
