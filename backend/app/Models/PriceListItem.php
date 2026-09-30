<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ordered pricing rule inside a PriceList (MRD §8.1 / FR-3).
 *
 * Evaluation is top-down by `sequence`, first match wins (FR-3.5). Matching
 * considers scope (product / category / all), min-max quantity and the
 * optional validity window; currency bridging and rounding happen in
 * PricingService, never here.
 */
class PriceListItem extends Model
{
    public const APPLY_PRODUCT = 'product';

    public const APPLY_CATEGORY = 'category';

    public const APPLY_ALL = 'all';

    public const COMPUTE_FIXED = 'fixed';

    public const COMPUTE_PERCENTAGE = 'percentage';

    public const COMPUTE_FORMULA = 'formula';

    public const BASE_LIST_PRICE = 'list_price';

    public const BASE_COST = 'cost';

    public const BASE_OTHER_PRICELIST = 'other_pricelist';

    protected $fillable = [
        'price_list_id',
        'sequence',
        'apply_on',
        'product_id',
        'category_id',
        'min_quantity',
        'max_quantity',
        'date_start',
        'date_end',
        'compute_type',
        'fixed_price',
        'percent',
        'base',
        'other_price_list_id',
        'markup_percent',
        'fixed_discount',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'min_quantity' => 'decimal:4',
        'max_quantity' => 'decimal:4',
        'date_start' => 'date',
        'date_end' => 'date',
        'fixed_price' => 'decimal:4',
        'percent' => 'decimal:4',
        'markup_percent' => 'decimal:4',
        'fixed_discount' => 'decimal:4',
    ];

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function otherPriceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'other_price_list_id');
    }

    /**
     * Whether this rule targets the given product under the list's ordering
     * (scope + qty band + date window — FR-3.5). Category scope honours the
     * ancestor chain so a rule on a parent category covers its children.
     *
     * @param  int[]  $categoryChain  product category id + ancestors, nearest first
     */
    public function matches(Product $product, float $quantity, ?string $date, array $categoryChain = []): bool
    {
        $day = $date ?? now()->toDateString();

        if ($this->date_start && $day < $this->date_start->toDateString()) {
            return false;
        }

        if ($this->date_end && $day > $this->date_end->toDateString()) {
            return false;
        }

        if ($quantity < (float) ($this->min_quantity ?? 1)) {
            return false;
        }

        if ($this->max_quantity !== null && $quantity > (float) $this->max_quantity) {
            return false;
        }

        return match ($this->apply_on) {
            self::APPLY_PRODUCT => $this->product_id !== null && (int) $this->product_id === (int) $product->id,
            self::APPLY_CATEGORY => $this->category_id !== null
                && array_intersect($categoryChain ?: [(int) $product->category_id], [(int) $this->category_id]) !== [],
            default => true,
        };
    }

    /**
     * Rules that expose cost-based bases must stay invisible to roles without
     * the cost permission (FR-3.6).
     */
    public function isCostBased(): bool
    {
        return $this->compute_type === self::COMPUTE_FORMULA && $this->base === self::BASE_COST;
    }
}
