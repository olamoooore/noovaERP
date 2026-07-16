<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerTier extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'fan_tier_id',
        'current_points',
        'lifetime_points',
        'events_attended',
        'total_spent',
        'tier_assigned_at',
        'tier_expires_at',
        'qualification_data',
    ];

    protected $casts = [
        'total_spent' => 'decimal:2',
        'tier_assigned_at' => 'datetime',
        'tier_expires_at' => 'datetime',
        'qualification_data' => 'array',
    ];

    /**
     * Get the customer that owns this tier assignment.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the fan tier for this assignment.
     */
    public function fanTier(): BelongsTo
    {
        return $this->belongsTo(FanTier::class);
    }

    /**
     * Get the loyalty point transactions for this customer.
     */
    public function pointTransactions(): HasMany
    {
        return $this->hasMany(LoyaltyPointTransaction::class, 'customer_id', 'customer_id')
            ->where('tenant_id', $this->tenant_id);
    }

    /**
     * Get the tier benefit usage for this customer.
     */
    public function benefitUsage(): HasMany
    {
        return $this->hasMany(TierBenefitUsage::class, 'customer_id', 'customer_id')
            ->where('tenant_id', $this->tenant_id);
    }

    /**
     * Scope to filter by tenant.
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to get non-expired tiers.
     */
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('tier_expires_at')
                ->orWhere('tier_expires_at', '>', now());
        });
    }

    /**
     * Check if the tier is currently active (not expired).
     */
    public function isActive(): bool
    {
        return $this->tier_expires_at === null || $this->tier_expires_at->isFuture();
    }

    /**
     * Add points to the customer's balance.
     */
    public function addPoints(int $points, string $source, ?int $sourceId = null, ?string $description = null): void
    {
        $this->increment('current_points', $points);
        $this->increment('lifetime_points', $points);

        // Create transaction record
        LoyaltyPointTransaction::create([
            'tenant_id' => $this->tenant_id,
            'customer_id' => $this->customer_id,
            'transaction_type' => 'earned',
            'points_amount' => $points,
            'source_type' => $source,
            'source_id' => $sourceId,
            'description' => $description,
            'expires_at' => now()->addYear(), // Points expire after 1 year
        ]);
    }

    /**
     * Redeem points from the customer's balance.
     */
    public function redeemPoints(int $points, string $source, ?int $sourceId = null, ?string $description = null): bool
    {
        if ($this->current_points < $points) {
            return false;
        }

        $this->decrement('current_points', $points);

        // Create transaction record
        LoyaltyPointTransaction::create([
            'tenant_id' => $this->tenant_id,
            'customer_id' => $this->customer_id,
            'transaction_type' => 'redeemed',
            'points_amount' => -$points,
            'source_type' => $source,
            'source_id' => $sourceId,
            'description' => $description,
        ]);

        return true;
    }

    /**
     * Update customer stats and check for tier progression.
     */
    public function updateStats(int $eventsAttended = 0, float $amountSpent = 0): void
    {
        if ($eventsAttended > 0) {
            $this->increment('events_attended', $eventsAttended);
        }

        if ($amountSpent > 0) {
            $this->increment('total_spent', $amountSpent);
        }
    }
}
