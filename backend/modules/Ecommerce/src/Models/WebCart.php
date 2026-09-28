<?php

/**
 * @deprecated Legacy single-tenant commerce stack — archived per MRD
 *             ecommerce-Enterprice Appendix A (FR-ARC-01). Frozen 2026-09-28:
 *             no new features; superseded by the generic modules/Storefront.
 *             Do not extend, copy or reuse as a platform foundation.
 */

namespace Modules\Ecommerce\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebCart extends Model
{
    use HasFactory;

    protected $table = 'web_carts';

    protected $fillable = [
        'customer_id',
        'guest_token',
        'items',
        'coupon_code',
        'expires_at',
    ];

    protected $casts = [
        'items' => 'array',
        'expires_at' => 'datetime',
    ];

    // Relationships
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    // Get cart total
    public function getTotalAttribute(): float
    {
        return collect($this->items)->sum(function ($item) {
            return ($item['price'] ?? 0) * ($item['quantity'] ?? 0);
        });
    }

    // Get item count
    public function getItemCountAttribute(): int
    {
        return collect($this->items)->sum('quantity');
    }
}
