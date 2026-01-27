<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\EventManagement\Traits\HasTenantScope;
use Modules\EventManagement\Traits\HasAuditTrail;

/**
 * Artist Model
 * 
 * Represents individual K-POP artists (solo or group members).
 * Supports comprehensive artist metadata, social media links,
 * and performance history tracking.
 * 
 * Requirements: 12.1, 12.5
 */
class Artist extends Model
{
    use HasFactory, SoftDeletes, HasTenantScope, HasAuditTrail;

    protected $fillable = [
        'tenant_id',
        'name',
        'stage_name',
        'korean_name',
        'gender',
        'birth_date',
        'debut_date',
        'agency',
        'nationality',
        'biography',
        'positions',
        'profile_image_url',
        'social_media_links',
        'official_colors',
        'status',
        'metadata',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'debut_date' => 'date',
        'positions' => 'array',
        'social_media_links' => 'array',
        'official_colors' => 'array',
        'metadata' => 'array',
    ];

    protected $dates = [
        'deleted_at',
    ];

    /**
     * Get the groups this artist belongs to
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_members')
            ->withPivot([
                'stage_name_in_group',
                'positions',
                'is_leader',
                'is_main_vocalist',
                'is_main_dancer',
                'is_main_rapper',
                'member_number',
                'joined_date',
                'left_date',
                'status',
                'departure_reason',
                'metadata'
            ])
            ->withTimestamps()
            ->wherePivot('deleted_at', null);
    }

    /**
     * Get the group memberships for this artist
     */
    public function groupMemberships(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    /**
     * Get the events this artist is associated with
     */
    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'artist_events')
            ->withPivot([
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
                'metadata'
            ])
            ->withTimestamps()
            ->wherePivot('deleted_at', null);
    }

    /**
     * Get the artist events for this artist
     */
    public function artistEvents(): HasMany
    {
        return $this->hasMany(ArtistEvent::class);
    }

    /**
     * Scope for active artists
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for artists by agency
     */
    public function scopeByAgency($query, string $agency)
    {
        return $query->where('agency', $agency);
    }

    /**
     * Scope for artists by debut year
     */
    public function scopeByDebutYear($query, int $year)
    {
        return $query->whereYear('debut_date', $year);
    }

    /**
     * Scope for solo artists (not in any active group)
     */
    public function scopeSolo($query)
    {
        return $query->whereDoesntHave('groups', function ($q) {
            $q->where('group_members.status', 'active');
        });
    }

    /**
     * Get the artist's display name (stage name or real name)
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->stage_name ?: $this->name;
    }

    /**
     * Get the artist's age
     */
    public function getAgeAttribute(): ?int
    {
        return $this->birth_date ? $this->birth_date->age : null;
    }

    /**
     * Get years since debut
     */
    public function getYearsSinceDebutAttribute(): ?int
    {
        return $this->debut_date ? $this->debut_date->diffInYears(now()) : null;
    }

    /**
     * Check if artist is currently in any active group
     */
    public function isInActiveGroup(): bool
    {
        return $this->groups()
            ->wherePivot('status', 'active')
            ->exists();
    }

    /**
     * Get artist's current active groups
     */
    public function getActiveGroups()
    {
        return $this->groups()
            ->wherePivot('status', 'active')
            ->get();
    }

    /**
     * Get artist's performance history with statistics
     */
    public function getPerformanceHistory()
    {
        return $this->artistEvents()
            ->with(['event'])
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($artistEvent) {
                return [
                    'event_id' => $artistEvent->event_id,
                    'event_title' => $artistEvent->event->title,
                    'event_date' => $artistEvent->event->start_date,
                    'role' => $artistEvent->role,
                    'performance_order' => $artistEvent->performance_order,
                    'performance_fee' => $artistEvent->performance_fee,
                ];
            });
    }

    /**
     * Get artist's total performance statistics
     */
    public function getPerformanceStats(): array
    {
        $completedEvents = $this->artistEvents()
            ->where('status', 'completed')
            ->count();

        $totalRevenue = $this->artistEvents()
            ->where('status', 'completed')
            ->sum('performance_fee');

        $mainPerformances = $this->artistEvents()
            ->where('status', 'completed')
            ->where('role', 'main')
            ->count();

        return [
            'total_performances' => $completedEvents,
            'total_revenue' => $totalRevenue,
            'main_performances' => $mainPerformances,
            'guest_performances' => $completedEvents - $mainPerformances,
        ];
    }

    /**
     * Search artists by name or stage name
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('stage_name', 'like', "%{$search}%")
              ->orWhere('korean_name', 'like', "%{$search}%");
        });
    }

    /**
     * Get social media link by platform
     */
    public function getSocialMediaLink(string $platform): ?string
    {
        return $this->social_media_links[$platform] ?? null;
    }

    /**
     * Add or update social media link
     */
    public function setSocialMediaLink(string $platform, string $url): void
    {
        $links = $this->social_media_links ?? [];
        $links[$platform] = $url;
        $this->social_media_links = $links;
    }

    /**
     * Check if artist has specific position
     */
    public function hasPosition(string $position): bool
    {
        return in_array($position, $this->positions ?? []);
    }

    /**
     * Add position to artist
     */
    public function addPosition(string $position): void
    {
        $positions = $this->positions ?? [];
        if (!in_array($position, $positions)) {
            $positions[] = $position;
            $this->positions = $positions;
        }
    }

    /**
     * Remove position from artist
     */
    public function removePosition(string $position): void
    {
        $positions = $this->positions ?? [];
        $this->positions = array_values(array_filter($positions, fn($p) => $p !== $position));
    }
}