<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\EventManagement\Traits\HasAuditTrail;
use Modules\EventManagement\Traits\HasTenantScope;

/**
 * Group Model
 *
 * Represents K-POP groups and bands with comprehensive metadata,
 * member management, and performance tracking capabilities.
 *
 * Requirements: 12.1, 12.5
 */
class Group extends Model
{
    use HasAuditTrail, HasFactory, HasTenantScope, SoftDeletes;

    protected $table = 'artist_groups';

    protected $fillable = [
        'tenant_id',
        'name',
        'korean_name',
        'english_name',
        'name_meaning',
        'debut_date',
        'formation_date',
        'agency',
        'sub_labels',
        'description',
        'concept',
        'genres',
        'member_count',
        'official_colors',
        'fandom_name',
        'logo_url',
        'profile_image_url',
        'social_media_links',
        'official_websites',
        'status',
        'metadata',
    ];

    protected $casts = [
        'debut_date' => 'date',
        'formation_date' => 'date',
        'sub_labels' => 'array',
        'genres' => 'array',
        'official_colors' => 'array',
        'social_media_links' => 'array',
        'official_websites' => 'array',
        'metadata' => 'array',
    ];

    protected $dates = [
        'deleted_at',
    ];

    /**
     * Get the artists that belong to this group
     */
    public function artists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class, 'group_members')
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
                'metadata',
            ])
            ->withTimestamps()
            ->wherePivot('deleted_at', null);
    }

    /**
     * Get the group memberships for this group
     */
    public function groupMemberships(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    /**
     * Get the events this group is associated with
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
                'metadata',
            ])
            ->withTimestamps()
            ->wherePivot('deleted_at', null);
    }

    /**
     * Get the artist events for this group
     */
    public function artistEvents(): HasMany
    {
        return $this->hasMany(ArtistEvent::class);
    }

    /**
     * Scope for active groups
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for groups by agency
     */
    public function scopeByAgency($query, string $agency)
    {
        return $query->where('agency', $agency);
    }

    /**
     * Scope for groups by debut year
     */
    public function scopeByDebutYear($query, int $year)
    {
        return $query->whereYear('debut_date', $year);
    }

    /**
     * Scope for groups by member count range
     */
    public function scopeByMemberCount($query, ?int $min = null, ?int $max = null)
    {
        if ($min !== null) {
            $query->where('member_count', '>=', $min);
        }
        if ($max !== null) {
            $query->where('member_count', '<=', $max);
        }

        return $query;
    }

    /**
     * Get the group's display name
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->english_name ?: $this->name;
    }

    /**
     * Get years since debut
     */
    public function getYearsSinceDebutAttribute(): ?int
    {
        return $this->debut_date ? $this->debut_date->diffInYears(now()) : null;
    }

    /**
     * Get years since formation
     */
    public function getYearsSinceFormationAttribute(): ?int
    {
        return $this->formation_date ? $this->formation_date->diffInYears(now()) : null;
    }

    /**
     * Get active members of the group
     */
    public function getActiveMembers()
    {
        return $this->artists()
            ->wherePivot('status', 'active')
            ->orderBy('group_members.member_number')
            ->get();
    }

    /**
     * Get the group leader
     */
    public function getLeader()
    {
        return $this->artists()
            ->wherePivot('status', 'active')
            ->wherePivot('is_leader', true)
            ->first();
    }

    /**
     * Get members by position
     */
    public function getMembersByPosition(string $position)
    {
        return $this->artists()
            ->wherePivot('status', 'active')
            ->whereJsonContains('group_members.positions', $position)
            ->get();
    }

    /**
     * Get main vocalists
     */
    public function getMainVocalists()
    {
        return $this->artists()
            ->wherePivot('status', 'active')
            ->wherePivot('is_main_vocalist', true)
            ->get();
    }

    /**
     * Get main dancers
     */
    public function getMainDancers()
    {
        return $this->artists()
            ->wherePivot('status', 'active')
            ->wherePivot('is_main_dancer', true)
            ->get();
    }

    /**
     * Get main rappers
     */
    public function getMainRappers()
    {
        return $this->artists()
            ->wherePivot('status', 'active')
            ->wherePivot('is_main_rapper', true)
            ->get();
    }

    /**
     * Update member count based on active members
     */
    public function updateMemberCount(): void
    {
        $this->member_count = $this->artists()
            ->wherePivot('status', 'active')
            ->count();
        $this->save();
    }

    /**
     * Get group's performance history with statistics
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
     * Get group's total performance statistics
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
     * Search groups by name
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('korean_name', 'like', "%{$search}%")
                ->orWhere('english_name', 'like', "%{$search}%")
                ->orWhere('fandom_name', 'like', "%{$search}%");
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
     * Get official website by type
     */
    public function getOfficialWebsite(string $type = 'main'): ?string
    {
        return $this->official_websites[$type] ?? null;
    }

    /**
     * Add or update official website
     */
    public function setOfficialWebsite(string $type, string $url): void
    {
        $websites = $this->official_websites ?? [];
        $websites[$type] = $url;
        $this->official_websites = $websites;
    }

    /**
     * Check if group has specific genre
     */
    public function hasGenre(string $genre): bool
    {
        return in_array($genre, $this->genres ?? []);
    }

    /**
     * Add genre to group
     */
    public function addGenre(string $genre): void
    {
        $genres = $this->genres ?? [];
        if (! in_array($genre, $genres)) {
            $genres[] = $genre;
            $this->genres = $genres;
        }
    }

    /**
     * Remove genre from group
     */
    public function removeGenre(string $genre): void
    {
        $genres = $this->genres ?? [];
        $this->genres = array_values(array_filter($genres, fn ($g) => $g !== $genre));
    }

    /**
     * Add member to group
     */
    public function addMember(Artist $artist, array $memberData = []): GroupMember
    {
        $groupMember = new GroupMember([
            'tenant_id' => $this->tenant_id,
            'group_id' => $this->id,
            'artist_id' => $artist->id,
            'stage_name_in_group' => $memberData['stage_name_in_group'] ?? null,
            'positions' => $memberData['positions'] ?? [],
            'is_leader' => $memberData['is_leader'] ?? false,
            'is_main_vocalist' => $memberData['is_main_vocalist'] ?? false,
            'is_main_dancer' => $memberData['is_main_dancer'] ?? false,
            'is_main_rapper' => $memberData['is_main_rapper'] ?? false,
            'member_number' => $memberData['member_number'] ?? null,
            'joined_date' => $memberData['joined_date'] ?? now(),
            'status' => $memberData['status'] ?? 'active',
            'metadata' => $memberData['metadata'] ?? [],
        ]);

        $groupMember->save();
        $this->updateMemberCount();

        return $groupMember;
    }

    /**
     * Remove member from group
     */
    public function removeMember(Artist $artist, ?string $reason = null): void
    {
        $this->groupMemberships()
            ->where('artist_id', $artist->id)
            ->where('status', 'active')
            ->update([
                'status' => 'graduated',
                'left_date' => now(),
                'departure_reason' => $reason,
            ]);

        $this->updateMemberCount();
    }
}
