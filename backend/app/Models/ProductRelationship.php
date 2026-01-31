<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProductRelationship Model
 * Cross-sell, up-sell, substitutes, accessories
 */
class ProductRelationship extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'related_product_id',
        'type',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public const TYPE_CROSS_SELL = 'cross_sell';
    public const TYPE_UP_SELL = 'up_sell';
    public const TYPE_SUBSTITUTE = 'substitute';
    public const TYPE_ACCESSORY = 'accessory';
    public const TYPE_COMPLEMENTARY = 'complementary';

    public static function types(): array
    {
        return [
            self::TYPE_CROSS_SELL,
            self::TYPE_UP_SELL,
            self::TYPE_SUBSTITUTE,
            self::TYPE_ACCESSORY,
            self::TYPE_COMPLEMENTARY,
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function relatedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'related_product_id');
    }
}
