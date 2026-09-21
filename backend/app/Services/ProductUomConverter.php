<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductUom;
use Illuminate\Validation\ValidationException;

/**
 * Issue #135: single server-side authority for unit-of-measure conversion.
 *
 * ProductUom models real conversion (1 Box = 12 Pieces) but nothing in POS,
 * Sales, Inventory or Ecommerce consulted it, so a UOM-scaled quantity would
 * silently be written to stock as base units. Every stock-affecting path that
 * accepts a `uom_id` must run the quantity through here first.
 */
class ProductUomConverter
{
    /**
     * Resolve a UOM that belongs to the given product, or fail validation.
     */
    public function resolve(int $productId, int $uomId): ProductUom
    {
        $uom = ProductUom::query()
            ->whereKey($uomId)
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->first();

        if (! $uom) {
            throw ValidationException::withMessages([
                'lines' => ["Unit of measure #{$uomId} does not belong to product #{$productId}."],
            ]);
        }

        return $uom;
    }

    /**
     * Convert a quantity entered in the given UOM into base units.
     */
    public function toBase(ProductUom $uom, float $quantity): float
    {
        return round($uom->convertToBase($quantity), 6);
    }

    /**
     * Per-base-unit price for a quantity priced in this UOM, rounded to the
     * storage scale (2dp). Non-divisible conversion factors can introduce
     * cent-level rounding — callers should prefer conversion factors that
     * divide the currency cleanly where exact totals are required.
     */
    public function baseUnitPrice(Product $product, ProductUom $uom): float
    {
        $factor = (float) $uom->conversion_factor;

        if ($factor <= 0) {
            throw ValidationException::withMessages([
                'lines' => ["Unit of measure #{$uom->id} has an invalid conversion factor."],
            ]);
        }

        return round($uom->calculatePrice((float) $product->price) / $factor, 2);
    }
}
