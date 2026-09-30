<?php

namespace Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorefrontPromotion extends Model
{
    public const TYPES = ['percent', 'fixed', 'free_shipping'];

    protected $table = 'storefront_promotions';

    protected $fillable = [
        'code', 'type', 'value', 'min_subtotal', 'usage_limit', 'per_account_limit',
        'starts_at', 'ends_at', 'is_active', 'description',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'min_subtotal' => 'decimal:2',
        'usage_limit' => 'integer',
        'per_account_limit' => 'integer',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function redemptions(): HasMany
    {
        return $this->hasMany(StorefrontPromotionRedemption::class, 'promotion_id');
    }

    public static function normalizeCode(?string $code): string
    {
        return mb_strtoupper(trim((string) $code));
    }
}
