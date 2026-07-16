<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TierBenefit extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'fan_tier_id',
        'benefit_type',
        'benefit_name',
        'description',
        'configuration',
        'is_active',
    ];

    protected $casts = [
        'configuration' => 'array',
        'is_active' => 'boolean',
    ];

    // Benefit type constants
    public const TYPE_EARLY_ACCESS = 'early_access';

    public const TYPE_DISCOUNT = 'discount';

    public const TYPE_EXCLUSIVE_TICKETS = 'exclusive_tickets';

    public const TYPE_PRIORITY_SUPPORT = 'priority_support';

    public const TYPE_POINTS_MULTIPLIER = 'points_multiplier';

    /**
     * Get the fan tier that owns this benefit.
     */
    public function fanTier(): BelongsTo
    {
        return $this->belongsTo(FanTier::class);
    }

    /**
     * Get the usage records for this benefit.
     */
    public function usageRecords(): HasMany
    {
        return $this->hasMany(TierBenefitUsage::class);
    }

    /**
     * Scope to filter by tenant.
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to get active benefits only.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter by benefit type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('benefit_type', $type);
    }

    /**
     * Get the discount percentage if this is a discount benefit.
     */
    public function getDiscountPercentage(): ?float
    {
        if ($this->benefit_type !== self::TYPE_DISCOUNT) {
            return null;
        }

        return $this->configuration['discount_percentage'] ?? null;
    }

    /**
     * Get the early access hours if this is an early access benefit.
     */
    public function getEarlyAccessHours(): ?int
    {
        if ($this->benefit_type !== self::TYPE_EARLY_ACCESS) {
            return null;
        }

        return $this->configuration['early_access_hours'] ?? null;
    }

    /**
     * Get the points multiplier if this is a points multiplier benefit.
     */
    public function getPointsMultiplier(): ?float
    {
        if ($this->benefit_type !== self::TYPE_POINTS_MULTIPLIER) {
            return null;
        }

        return $this->configuration['multiplier'] ?? null;
    }

    /**
     * Check if this benefit applies to a specific context.
     */
    public function appliesTo(string $context, array $contextData = []): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $applicableContexts = $this->configuration['applicable_contexts'] ?? [];

        if (empty($applicableContexts)) {
            return true; // Applies to all contexts if none specified
        }

        return in_array($context, $applicableContexts);
    }

    /**
     * Record usage of this benefit.
     */
    public function recordUsage(int $customerId, string $usageType, string $sourceType, ?int $sourceId = null, array $usageData = []): void
    {
        TierBenefitUsage::create([
            'tenant_id' => $this->tenant_id,
            'customer_id' => $customerId,
            'tier_benefit_id' => $this->id,
            'usage_type' => $usageType,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'usage_data' => $usageData,
            'used_at' => now(),
        ]);
    }
}
