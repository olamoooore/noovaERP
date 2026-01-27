<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FanTier extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'description',
        'minimum_points',
        'minimum_events_attended',
        'minimum_total_spent',
        'priority_level',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'minimum_total_spent' => 'decimal:2',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * Get the customer tiers for this fan tier.
     */
    public function customerTiers(): HasMany
    {
        return $this->hasMany(CustomerTier::class);
    }

    /**
     * Get the tier benefits for this fan tier.
     */
    public function tierBenefits(): HasMany
    {
        return $this->hasMany(TierBenefit::class);
    }

    /**
     * Get the early access windows for this fan tier.
     */
    public function earlyAccessWindows(): HasMany
    {
        return $this->hasMany(EarlyAccessWindow::class);
    }

    /**
     * Scope to filter by tenant.
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to get active tiers only.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to order by priority level (highest first).
     */
    public function scopeByPriority($query)
    {
        return $query->orderBy('priority_level', 'desc');
    }

    /**
     * Check if a customer qualifies for this tier based on their stats.
     */
    public function customerQualifies(int $points, int $eventsAttended, float $totalSpent): bool
    {
        return $points >= $this->minimum_points
            && $eventsAttended >= $this->minimum_events_attended
            && $totalSpent >= $this->minimum_total_spent;
    }

    /**
     * Get the next higher tier if available.
     */
    public function getNextTier(): ?self
    {
        return static::forTenant($this->tenant_id)
            ->active()
            ->where('priority_level', '>', $this->priority_level)
            ->orderBy('priority_level', 'asc')
            ->first();
    }
}