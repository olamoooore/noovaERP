<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #337 — a signed, expiring, revocable customer portal link.
 *
 * Holds only the token HASH; the bearer token itself is never persisted.
 */
class CustomerPortalLink extends Model
{
    protected $table = 'customer_portal_links';

    protected $fillable = [
        'customer_id',
        'legal_entity_id',
        'token_hash',
        'label',
        'expires_at',
        'revoked_at',
        'revoked_by',
        'last_accessed_at',
        'created_by',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'customer_id' => 'integer',
        'legal_entity_id' => 'integer',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_accessed_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }
}
