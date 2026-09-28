<?php

namespace Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorefrontCart extends Model
{
    protected $table = 'storefront_carts';

    protected $fillable = [
        'account_id', 'guest_token_hash', 'currency', 'status',
        'abandoned_emitted_at', 'expires_at',
    ];

    protected $casts = [
        'abandoned_emitted_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(StorefrontAccount::class, 'account_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StorefrontCartItem::class, 'cart_id');
    }
}
