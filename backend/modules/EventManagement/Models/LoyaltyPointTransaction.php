<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyPointTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'transaction_type',
        'points_amount',
        'source_type',
        'source_id',
        'description',
        'expires_at',
        'metadata',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    // Transaction type constants
    public const TYPE_EARNED = 'earned';

    public const TYPE_REDEEMED = 'redeemed';

    public const TYPE_EXPIRED = 'expired';

    public const TYPE_ADJUSTED = 'adjusted';

    // Source type constants
    public const SOURCE_BOOKING = 'booking';

    public const SOURCE_PURCHASE = 'purchase';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_EXPIRATION = 'expiration';

    public const SOURCE_BONUS = 'bonus';

    /**
     * Get the customer that owns this transaction.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
     * Scope to filter by transaction type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('transaction_type', $type);
    }

    /**
     * Scope to get earned points only.
     */
    public function scopeEarned($query)
    {
        return $query->where('transaction_type', self::TYPE_EARNED);
    }

    /**
     * Scope to get redeemed points only.
     */
    public function scopeRedeemed($query)
    {
        return $query->where('transaction_type', self::TYPE_REDEEMED);
    }

    /**
     * Scope to get expired points.
     */
    public function scopeExpired($query)
    {
        return $query->where('transaction_type', self::TYPE_EXPIRED);
    }

    /**
     * Scope to get points that are expiring soon.
     */
    public function scopeExpiringSoon($query, int $days = 30)
    {
        return $query->where('transaction_type', self::TYPE_EARNED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays($days))
            ->where('expires_at', '>', now());
    }

    /**
     * Scope to get points that have already expired.
     */
    public function scopeAlreadyExpired($query)
    {
        return $query->where('transaction_type', self::TYPE_EARNED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    /**
     * Check if this transaction represents earned points that have expired.
     */
    public function hasExpired(): bool
    {
        return $this->transaction_type === self::TYPE_EARNED
            && $this->expires_at !== null
            && $this->expires_at <= now();
    }

    /**
     * Check if this transaction represents earned points that are expiring soon.
     */
    public function isExpiringSoon(int $days = 30): bool
    {
        return $this->transaction_type === self::TYPE_EARNED
            && $this->expires_at !== null
            && $this->expires_at <= now()->addDays($days)
            && $this->expires_at > now();
    }

    /**
     * Get the days until expiration.
     */
    public function getDaysUntilExpiration(): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        return max(0, $this->expires_at->diffInDays(now()));
    }
}
