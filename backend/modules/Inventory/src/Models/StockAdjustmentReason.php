<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class StockAdjustmentReason extends Model
{
    /** Default mapping key for value-reducing adjustments (write-offs). */
    public const DEFAULT_DECREASE_MAPPING_KEY = 'inventory_shrinkage';

    /**
     * @deprecated #1017 decision (2026-10-07, option A): stock increases no
     * longer post to a gain account (the 7300 mapping was removed). Kept only
     * for historical references; glMappingKey() returns null for an unmapped
     * increase so the service skips the GL posting instead of failing.
     */
    public const DEFAULT_INCREASE_MAPPING_KEY = 'inventory_adjustment_gain';

    protected $fillable = [
        'name',
        'code',
        'type',
        'expense_account_mapping_key',
        'requires_approval',
        'is_active',
    ];

    protected $casts = [
        'requires_approval' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForType($query, string $type)
    {
        return $query->where(function ($q) use ($type) {
            $q->where('type', $type)->orWhere('type', 'both');
        });
    }

    /**
     * Issue #106: the GL mapping key to use for this reason's valuation impact.
     * Explicit reason mapping wins; otherwise a decrease falls back to the
     * shrinkage default. #1017 decision (2026-10-07, option A): an increase has
     * no default — null means "no GL posting" for the increase.
     */
    public function glMappingKey(bool $isIncrease): ?string
    {
        $key = trim((string) $this->expense_account_mapping_key);

        if ($key !== '') {
            return $key;
        }

        return $isIncrease
            ? null
            : self::DEFAULT_DECREASE_MAPPING_KEY;
    }
}
