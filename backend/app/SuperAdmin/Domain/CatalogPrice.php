<?php

declare(strict_types=1);

namespace App\SuperAdmin\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogPrice extends Model
{
    protected $fillable = [
        'catalog_item_id',
        'billing_interval',
        'currency',
        'unit_amount',
    ];

    protected $casts = [
        'unit_amount' => 'integer',
    ];

    // ── Constants ────────────────────────────────────────────────────────────

    public const INTERVAL_MONTHLY = 'monthly';

    public const INTERVAL_YEARLY = 'yearly';

    public const INTERVALS = [self::INTERVAL_MONTHLY, self::INTERVAL_YEARLY];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForInterval($query, string $interval)
    {
        return $query->where('billing_interval', $interval);
    }

    public function scopeForCurrency($query, string $currency = 'USD')
    {
        return $query->where('currency', $currency);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Format unit_amount from cents to a human-readable dollar string.
     */
    public function formattedAmount(): string
    {
        return '$'.number_format($this->unit_amount / 100, 2);
    }
}
