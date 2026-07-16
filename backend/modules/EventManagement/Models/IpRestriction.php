<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class IpRestriction extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'ip_address',
        'ip_range',
        'restriction_type',
        'reason',
        'violation_count',
        'first_violation_at',
        'last_violation_at',
        'expires_at',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'first_violation_at' => 'datetime',
        'last_violation_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    // Restriction type constants
    public const TYPE_BLOCKED = 'blocked';

    public const TYPE_ALLOWED = 'allowed';

    public const TYPE_RATE_LIMITED = 'rate_limited';

    public const TYPE_FLAGGED = 'flagged';

    /**
     * Scope to filter by tenant.
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to get active restrictions only.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Scope to filter by restriction type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('restriction_type', $type);
    }

    /**
     * Scope to get expired restrictions.
     */
    public function scopeExpired($query)
    {
        return $query->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    /**
     * Check if the restriction is currently active.
     */
    public function isCurrentlyActive(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->expires_at && $this->expires_at <= now()) {
            return false;
        }

        return true;
    }

    /**
     * Check if an IP address matches this restriction.
     */
    public function matchesIp(string $ipAddress): bool
    {
        // Exact IP match
        if ($this->ip_address === $ipAddress) {
            return true;
        }

        // CIDR range match
        if ($this->ip_range && $this->ipInRange($ipAddress, $this->ip_range)) {
            return true;
        }

        return false;
    }

    /**
     * Check if an IP is within a CIDR range.
     */
    private function ipInRange(string $ip, string $range): bool
    {
        if (strpos($range, '/') === false) {
            return $ip === $range;
        }

        [$subnet, $bits] = explode('/', $range);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // IPv4
            $ip = ip2long($ip);
            $subnet = ip2long($subnet);
            $mask = -1 << (32 - $bits);
            $subnet &= $mask;

            return ($ip & $mask) === $subnet;
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // IPv6 - simplified check
            $ip = inet_pton($ip);
            $subnet = inet_pton($subnet);
            $binaryMask = str_repeat('f', $bits / 4).str_repeat('0', (128 - $bits) / 4);
            $mask = pack('H*', $binaryMask);

            return ($ip & $mask) === ($subnet & $mask);
        }

        return false;
    }

    /**
     * Record a violation for this IP.
     */
    public function recordViolation(): void
    {
        $this->increment('violation_count');

        if (! $this->first_violation_at) {
            $this->update(['first_violation_at' => now()]);
        }

        $this->update(['last_violation_at' => now()]);
    }

    /**
     * Extend the restriction expiration.
     */
    public function extendExpiration(int $hours): void
    {
        $newExpiration = $this->expires_at
            ? $this->expires_at->addHours($hours)
            : now()->addHours($hours);

        $this->update(['expires_at' => $newExpiration]);
    }

    /**
     * Get the restriction severity based on violation count.
     */
    public function getSeverity(): string
    {
        if ($this->violation_count >= 10) {
            return 'critical';
        } elseif ($this->violation_count >= 5) {
            return 'high';
        } elseif ($this->violation_count >= 2) {
            return 'medium';
        }

        return 'low';
    }
}
