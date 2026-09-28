<?php

namespace Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontSession extends Model
{
    protected $table = 'storefront_sessions';

    protected $fillable = [
        'storefront_account_id', 'token_hash', 'refresh_hash',
        'expires_at', 'refresh_expires_at', 'last_used_at', 'revoked_at',
        'device', 'ip',
    ];

    protected $hidden = ['token_hash', 'refresh_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        // Explicit FK: column is storefront_account_id; belongsTo would infer
        // 'account_id' from the relation name.
        return $this->belongsTo(StorefrontAccount::class, 'storefront_account_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
