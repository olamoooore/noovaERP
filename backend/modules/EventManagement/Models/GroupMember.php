<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\EventManagement\Traits\HasAuditTrail;
use Modules\EventManagement\Traits\HasTenantScope;

/**
 * GroupMember Model
 *
 * Represents the relationship between artists and groups,
 * including roles, positions, and membership timeline.
 *
 * Requirements: 12.1, 12.5
 */
class GroupMember extends Model
{
    use HasAuditTrail, HasFactory, HasTenantScope, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'group_id',
        'artist_id',
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
    ];

    protected $casts = [
        'positions' => 'array',
        'is_leader' => 'boolean',
        'is_main_vocalist' => 'boolean',
        'is_main_dancer' => 'boolean',
        'is_main_rapper' => 'boolean',
        'joined_date' => 'date',
        'left_date' => 'date',
        'metadata' => 'array',
    ];

    protected $dates = [
        'deleted_at',
    ];

    /**
     * Get the group this membership belongs to
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Get the artist this membership belongs to
     */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    /**
     * Scope for active memberships
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for leaders
     */
    public function scopeLeaders($query)
    {
        return $query->where('is_leader', true);
    }

    /**
     * Scope for main vocalists
     */
    public function scopeMainVocalists($query)
    {
        return $query->where('is_main_vocalist', true);
    }

    /**
     * Scope for main dancers
     */
    public function scopeMainDancers($query)
    {
        return $query->where('is_main_dancer', true);
    }

    /**
     * Scope for main rappers
     */
    public function scopeMainRappers($query)
    {
        return $query->where('is_main_rapper', true);
    }

    /**
     * Scope for members by position
     */
    public function scopeByPosition($query, string $position)
    {
        return $query->whereJsonContains('positions', $position);
    }

    /**
     * Get the member's display name in the group
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->stage_name_in_group ?: $this->artist->display_name;
    }

    /**
     * Get years in the group
     */
    public function getYearsInGroupAttribute(): ?int
    {
        if (! $this->joined_date) {
            return null;
        }

        $endDate = $this->left_date ?: now();

        return $this->joined_date->diffInYears($endDate);
    }

    /**
     * Check if member is currently active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if member has specific position
     */
    public function hasPosition(string $position): bool
    {
        return in_array($position, $this->positions ?? []);
    }

    /**
     * Add position to member
     */
    public function addPosition(string $position): void
    {
        $positions = $this->positions ?? [];
        if (! in_array($position, $positions)) {
            $positions[] = $position;
            $this->positions = $positions;
        }
    }

    /**
     * Remove position from member
     */
    public function removePosition(string $position): void
    {
        $positions = $this->positions ?? [];
        $this->positions = array_values(array_filter($positions, fn ($p) => $p !== $position));
    }

    /**
     * Get all main positions for this member
     */
    public function getMainPositions(): array
    {
        $mainPositions = [];

        if ($this->is_leader) {
            $mainPositions[] = 'Leader';
        }
        if ($this->is_main_vocalist) {
            $mainPositions[] = 'Main Vocalist';
        }
        if ($this->is_main_dancer) {
            $mainPositions[] = 'Main Dancer';
        }
        if ($this->is_main_rapper) {
            $mainPositions[] = 'Main Rapper';
        }

        return $mainPositions;
    }

    /**
     * Get all positions (main + additional)
     */
    public function getAllPositions(): array
    {
        $allPositions = $this->getMainPositions();

        if ($this->positions) {
            $allPositions = array_merge($allPositions, $this->positions);
        }

        return array_unique($allPositions);
    }

    /**
     * Set member as leader
     */
    public function setAsLeader(): void
    {
        // Remove leader status from other members in the same group
        static::where('group_id', $this->group_id)
            ->where('id', '!=', $this->id)
            ->update(['is_leader' => false]);

        $this->is_leader = true;
        $this->save();
    }

    /**
     * Remove leader status
     */
    public function removeLeaderStatus(): void
    {
        $this->is_leader = false;
        $this->save();
    }

    /**
     * Graduate member from group
     */
    public function graduate(?string $reason = null): void
    {
        $this->status = 'graduated';
        $this->left_date = now();
        $this->departure_reason = $reason;
        $this->save();

        // Update group member count
        $this->group->updateMemberCount();
    }

    /**
     * Put member on hiatus
     */
    public function putOnHiatus(?string $reason = null): void
    {
        $this->status = 'hiatus';
        $this->departure_reason = $reason;
        $this->save();
    }

    /**
     * Return member from hiatus
     */
    public function returnFromHiatus(): void
    {
        $this->status = 'active';
        $this->departure_reason = null;
        $this->save();
    }

    /**
     * Get membership duration in days
     */
    public function getMembershipDuration(): int
    {
        $endDate = $this->left_date ?: now();

        return $this->joined_date ? $this->joined_date->diffInDays($endDate) : 0;
    }

    /**
     * Check if member joined before a specific date
     */
    public function joinedBefore(\Carbon\Carbon $date): bool
    {
        return $this->joined_date && $this->joined_date->lt($date);
    }

    /**
     * Check if member left after a specific date
     */
    public function leftAfter(\Carbon\Carbon $date): bool
    {
        return $this->left_date && $this->left_date->gt($date);
    }

    /**
     * Get member's contribution to group (based on positions and tenure)
     */
    public function getContributionScore(): int
    {
        $score = 0;

        // Base score for being active
        if ($this->isActive()) {
            $score += 10;
        }

        // Leadership bonus
        if ($this->is_leader) {
            $score += 20;
        }

        // Main position bonuses
        if ($this->is_main_vocalist) {
            $score += 15;
        }
        if ($this->is_main_dancer) {
            $score += 15;
        }
        if ($this->is_main_rapper) {
            $score += 15;
        }

        // Additional positions
        $score += count($this->positions ?? []) * 5;

        // Tenure bonus (1 point per month)
        if ($this->joined_date) {
            $months = $this->joined_date->diffInMonths($this->left_date ?: now());
            $score += $months;
        }

        return $score;
    }
}
