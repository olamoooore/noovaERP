<?php

namespace Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontAccountToken extends Model
{
    public const PURPOSE_VERIFICATION = 'verification';

    public const PURPOSE_RESET = 'reset';

    protected $table = 'storefront_account_tokens';

    protected $fillable = ['account_id', 'purpose', 'token_hash', 'expires_at', 'consumed_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(StorefrontAccount::class, 'account_id');
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
