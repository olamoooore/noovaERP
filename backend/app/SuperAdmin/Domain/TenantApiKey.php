<?php

declare(strict_types=1);

namespace App\SuperAdmin\Domain;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TenantApiKey extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'key_hash',
        'key_prefix',
        'is_active',
        'last_used_at',
        'expires_at',
        'created_by_admin_id',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'last_used_at' => 'datetime',
        'expires_at'   => 'datetime',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    // ── Static factory ────────────────────────────────────────────────────────

    /**
     * Generate a new API key, persist the hash, return the raw key (shown once only).
     *
     * Key format: ten_{random40chars}
     */
    public static function generate(
        string $tenantId,
        string $name,
        ?int   $adminId = null,
        ?\Carbon\Carbon $expiresAt = null,
    ): array {
        $raw    = 'ten_' . Str::random(40);
        $hash   = hash('sha256', $raw);
        $prefix = substr($raw, 0, 12);

        $model = static::create([
            'tenant_id'           => $tenantId,
            'name'                => $name,
            'key_hash'            => $hash,
            'key_prefix'          => $prefix,
            'is_active'           => true,
            'expires_at'          => $expiresAt,
            'created_by_admin_id' => $adminId,
        ]);

        // Return both — raw key must be stored by the caller, it won't be shown again
        return ['model' => $model, 'raw_key' => $raw];
    }

    /**
     * Find a key record by its raw value (hash lookup).
     */
    public static function findByRawKey(string $rawKey): ?static
    {
        return static::where('key_hash', hash('sha256', $rawKey))->first();
    }

    /**
     * Mark this key as used right now.
     * Renamed to avoid conflicting with Eloquent\Model::touch($attribute = null).
     */
    public function recordUsage(): bool
    {
        return $this->update(['last_used_at' => now()]);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
