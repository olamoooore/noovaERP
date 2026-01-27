<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class IdentityVerification extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'identity_verifications';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'verification_level',
        'verification_status',
        'personal_info',
        'document_paths',
        'document_metadata',
        'submitted_at',
        'verified_at',
        'expires_at',
        'rejection_reason',
        'verification_metadata',
    ];

    protected $casts = [
        'personal_info' => 'array',
        'document_paths' => 'array',
        'document_metadata' => 'array',
        'verification_metadata' => 'array',
        'submitted_at' => 'datetime',
        'verified_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    // Verification levels
    const LEVEL_NONE = 'none';
    const LEVEL_BASIC = 'basic';
    const LEVEL_FULL = 'full';

    // Verification statuses
    const STATUS_NONE = 'none';
    const STATUS_PENDING = 'pending';
    const STATUS_VERIFIED = 'verified';
    const STATUS_REJECTED = 'rejected';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_SUPERSEDED = 'superseded';

    /**
     * Scope for tenant isolation
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Get document types that were submitted
     */
    public function getDocumentTypes(): array
    {
        return array_keys($this->document_paths ?? []);
    }

    /**
     * Check if verification is currently valid
     */
    public function isValid(): bool
    {
        return $this->verification_status === self::STATUS_VERIFIED &&
               ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * Check if verification is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Get verification level priority (higher number = higher level)
     */
    public function getLevelPriority(): int
    {
        switch ($this->verification_level) {
            case self::LEVEL_FULL:
                return 3;
            case self::LEVEL_BASIC:
                return 2;
            case self::LEVEL_NONE:
            default:
                return 1;
        }
    }

    /**
     * Check if this verification meets the required level
     */
    public function meetsRequiredLevel(string $requiredLevel): bool
    {
        if (!$this->isValid()) {
            return false;
        }

        $currentPriority = $this->getLevelPriority();
        $requiredPriority = match ($requiredLevel) {
            self::LEVEL_FULL => 3,
            self::LEVEL_BASIC => 2,
            default => 1,
        };

        return $currentPriority >= $requiredPriority;
    }

    /**
     * Get the customer associated with this verification
     */
    public function customer()
    {
        return $this->belongsTo(\App\Models\Customer::class);
    }

    /**
     * Get verification history for a customer
     */
    public static function getCustomerHistory(string $tenantId, int $customerId)
    {
        return static::forTenant($tenantId)
            ->where('customer_id', $customerId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get current valid verification for a customer
     */
    public static function getCurrentVerification(string $tenantId, int $customerId)
    {
        return static::forTenant($tenantId)
            ->where('customer_id', $customerId)
            ->where('verification_status', self::STATUS_VERIFIED)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
            })
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Check if customer has valid verification at required level
     */
    public static function hasValidVerification(string $tenantId, int $customerId, string $requiredLevel): bool
    {
        $verification = static::getCurrentVerification($tenantId, $customerId);
        
        return $verification && $verification->meetsRequiredLevel($requiredLevel);
    }

    /**
     * Mark verification as expired if past expiry date
     */
    public function checkAndMarkExpired(): bool
    {
        if ($this->isExpired() && $this->verification_status === self::STATUS_VERIFIED) {
            $this->update(['verification_status' => self::STATUS_EXPIRED]);
            return true;
        }
        
        return false;
    }

    /**
     * Set verification as verified with expiry date
     */
    public function markAsVerified(?int $validityMonths = null): void
    {
        $expiryDate = null;
        
        if ($validityMonths) {
            $expiryDate = now()->addMonths($validityMonths);
        } else {
            // Default expiry based on verification level
            $expiryDate = match ($this->verification_level) {
                self::LEVEL_FULL => now()->addMonths(24), // 2 years
                self::LEVEL_BASIC => now()->addMonths(12), // 1 year
                default => now()->addMonths(6), // 6 months
            };
        }

        $this->update([
            'verification_status' => self::STATUS_VERIFIED,
            'verified_at' => now(),
            'expires_at' => $expiryDate,
        ]);
    }

    /**
     * Reject verification with reason
     */
    public function reject(string $reason): void
    {
        $this->update([
            'verification_status' => self::STATUS_REJECTED,
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * Cancel pending verification
     */
    public function cancel(): void
    {
        if ($this->verification_status === self::STATUS_PENDING) {
            $this->update(['verification_status' => self::STATUS_CANCELLED]);
        }
    }
}