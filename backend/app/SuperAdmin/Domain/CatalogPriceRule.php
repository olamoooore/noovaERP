<?php

namespace App\SuperAdmin\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogPriceRule extends Model
{
    protected $fillable = [
        'catalog_item_id',
        'name',
        'rule_type',
        'condition',
        'modifier_value',
        'priority',
        'is_exclusive',
        'is_active',
        'effective_from',
        'effective_to',
    ];

    protected $casts = [
        'condition'      => 'array',
        'modifier_value' => 'decimal:4',
        'priority'       => 'integer',
        'is_exclusive'   => 'boolean',
        'is_active'      => 'boolean',
        'effective_from' => 'date',
        'effective_to'   => 'date',
    ];

    // ── Constants ────────────────────────────────────────────────────────────

    public const TYPE_PERCENTAGE_DISCOUNT = 'percentage_discount';
    public const TYPE_FIXED_DISCOUNT      = 'fixed_discount';
    public const TYPE_YEARLY_DISCOUNT     = 'yearly_discount';
    public const TYPE_VOLUME_TIER         = 'volume_tier';

    public const RULE_TYPES = [
        self::TYPE_PERCENTAGE_DISCOUNT,
        self::TYPE_FIXED_DISCOUNT,
        self::TYPE_YEARLY_DISCOUNT,
        self::TYPE_VOLUME_TIER,
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeEffective($query)
    {
        return $query->active()
            ->where('effective_from', '<=', now()->toDateString())
            ->where(function ($q) {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>=', now()->toDateString());
            });
    }

    public function scopeByPriority($query)
    {
        return $query->orderByDesc('priority');
    }

    public function scopeGlobal($query)
    {
        return $query->whereNull('catalog_item_id');
    }

    public function scopeForItem($query, int $catalogItemId)
    {
        return $query->where('catalog_item_id', $catalogItemId);
    }
}
