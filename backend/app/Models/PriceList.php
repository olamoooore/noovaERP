<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * General Price List (MRD pricelist-multicurrency-mrd §8.1).
 *
 * Odoo-parity: one currency per list, ordered rules evaluated top-down with
 * first-match-wins, applicability rows (all / customer group / customer),
 * per-list rounding and a discount display policy. Resolution is owned by
 * App\Services\PricingService — no module may re-implement it (SOP §4).
 */
class PriceList extends Model
{
    use HasFactory;

    public const ROUNDING_UP = 'up';

    public const ROUNDING_DOWN = 'down';

    public const ROUNDING_NEAREST = 'nearest';

    public const DISPLAY_SHOW_DISCOUNT = 'show_discount';

    public const DISPLAY_SHOW_DISCOUNTED_PRICE = 'show_discounted_price';

    protected $fillable = [
        'name',
        'code',
        'currency_code',
        'description',
        'sequence',
        'valid_from',
        'valid_until',
        'is_active',
        'rounding_enabled',
        'rounding_method',
        'rounding_precision',
        'discount_display',
        'created_by',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'is_active' => 'boolean',
        'rounding_enabled' => 'boolean',
        'rounding_precision' => 'decimal:4',
    ];

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class)->orderBy('sequence')->orderBy('id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(PriceListApplication::class);
    }

    /**
     * Customers that hold this list as their default price list (FR-2.4).
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'price_list_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sequence')->orderByRaw('valid_from DESC NULLS LAST')->orderBy('id');
    }

    /**
     * Whether the list may price documents on the given date
     * (FR-2.1 validity + FR-2.5 deactivation drops it from resolution).
     */
    public function appliesOnDate($date = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $day = ($date ? \Carbon\Carbon::parse($date) : now())->toDateString();

        if ($this->valid_from && $day < $this->valid_from->toDateString()) {
            return false;
        }

        if ($this->valid_until && $day > $this->valid_until->toDateString()) {
            return false;
        }

        return true;
    }

    /**
     * Round a unit price using this list's rounding policy (FR-3.7 / FR-9.4:
     * applied to the UNIT price, never the line total).
     */
    public function applyRounding(float $price): float
    {
        if (! $this->rounding_enabled) {
            return $price;
        }

        $precision = (float) $this->rounding_precision;

        if ($precision <= 0) {
            return $price;
        }

        return match ($this->rounding_method) {
            self::ROUNDING_UP => ceil($price / $precision) * $precision,
            self::ROUNDING_DOWN => floor($price / $precision) * $precision,
            default => round($price / $precision) * $precision,
        };
    }
}
