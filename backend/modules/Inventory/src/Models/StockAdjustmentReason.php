<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class StockAdjustmentReason extends Model
{
    /** Default mapping key for value-reducing adjustments (write-offs). */
    public const DEFAULT_DECREASE_MAPPING_KEY = 'inventory_shrinkage';

    /** Default mapping key for value-increasing adjustments (found stock). */
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
     * Explicit reason mapping wins; otherwise direction-appropriate default.
     */
    public function glMappingKey(bool $isIncrease): string
    {
        $key = trim((string) $this->expense_account_mapping_key);

        if ($key !== '') {
            return $key;
        }

        return $isIncrease
            ? self::DEFAULT_INCREASE_MAPPING_KEY
            : self::DEFAULT_DECREASE_MAPPING_KEY;
    }
}
