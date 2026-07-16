<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TierBenefitUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'tier_benefit_id',
        'usage_type',
        'source_type',
        'source_id',
        'usage_data',
        'used_at',
    ];

    protected $casts = [
        'usage_data' => 'array',
        'used_at' => 'datetime',
    ];

    // Usage type constants
    public const USAGE_EARLY_ACCESS = 'early_access_used';

    public const USAGE_DISCOUNT_APPLIED = 'discount_applied';

    public const USAGE_EXCLUSIVE_TICKET = 'exclusive_ticket_used';

    public const USAGE_PRIORITY_SUPPORT = 'priority_support_used';

    public const USAGE_POINTS_MULTIPLIER = 'points_multiplier_applied';

    /**
     * Get the customer that used this benefit.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the tier benefit that was used.
     */
    public function tierBenefit(): BelongsTo
    {
        return $this->belongsTo(TierBenefit::class);
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
     * Scope to filter by tier benefit.
     */
    public function scopeForTierBenefit($query, int $tierBenefitId)
    {
        return $query->where('tier_benefit_id', $tierBenefitId);
    }

    /**
     * Scope to filter by usage type.
     */
    public function scopeOfType($query, string $usageType)
    {
        return $query->where('usage_type', $usageType);
    }

    /**
     * Scope to filter by date range.
     */
    public function scopeUsedBetween($query, $startDate, $endDate)
    {
        return $query->whereBetween('used_at', [$startDate, $endDate]);
    }

    /**
     * Scope to get usage within a specific time period.
     */
    public function scopeUsedWithinDays($query, int $days)
    {
        return $query->where('used_at', '>=', now()->subDays($days));
    }

    /**
     * Get the discount amount if this was a discount usage.
     */
    public function getDiscountAmount(): ?float
    {
        if ($this->usage_type !== self::USAGE_DISCOUNT_APPLIED) {
            return null;
        }

        return $this->usage_data['discount_amount'] ?? null;
    }

    /**
     * Get the points multiplier if this was a points multiplier usage.
     */
    public function getPointsMultiplier(): ?float
    {
        if ($this->usage_type !== self::USAGE_POINTS_MULTIPLIER) {
            return null;
        }

        return $this->usage_data['multiplier'] ?? null;
    }

    /**
     * Get the early access window ID if this was early access usage.
     */
    public function getEarlyAccessWindowId(): ?int
    {
        if ($this->usage_type !== self::USAGE_EARLY_ACCESS) {
            return null;
        }

        return $this->usage_data['early_access_window_id'] ?? null;
    }
}
