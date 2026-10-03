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
     * Issue #685: "base units" are the product's STOCK UoM. Every stock/COGS
     * path (invoice posting, delivery-order goods issue, availability checks,
     * lot/serial consumption) converts a sales-line quantity through here —
     * one rule, no second implementation of quantity × factor.
     */
    public function toStock(ProductUom $uom, float $quantity): float
    {
        return $this->toBase($uom, $quantity);
    }

    /**
     * Issue #685: stock units → the given sales UOM (the inverse direction,
     * used when a stock-denominated document line is re-expressed in its
     * sales unit).
     */
    public function fromStock(ProductUom $uom, float $stockQuantity): float
    {
        $factor = $this->factor($uom);

        return round($stockQuantity / $factor, 6);
    }

    /**
     * Issue #685: a price expressed per sales UOM → the per-stock-unit price
     * used by the existing sales-order/stock pricing internals.
     */
    public function salesPriceToBase(ProductUom $uom, float $salesPrice): float
    {
        return round($salesPrice / $this->factor($uom), 6);
    }

    /**
     * Issue #685: a per-stock-unit price → the price per sales UOM
     * (the document-facing side of a converted line).
     */
    public function basePriceToSales(ProductUom $uom, float $basePrice): float
    {
        return round($basePrice * $this->factor($uom), 6);
    }

    /**
     * Issue #685: null-safe resolution for optional `uom_id` payloads. A line
     * without a UoM keeps stock-unit semantics, so callers fall back to null.
     */
    public function resolveOrNull(?int $productId, ?int $uomId): ?ProductUom
    {
        if ($productId === null || $uomId === null) {
            return null;
        }

        return $this->resolve($productId, $uomId);
    }

    /**
     * Issue #685: the product's stock UoM (base unit), if one is configured.
     */
    public function stockUom(int $productId): ?ProductUom
    {
        return ProductUom::query()
            ->where('product_id', $productId)
            ->where('is_base_unit', true)
            ->where('is_active', true)
            ->first();
    }

    private function factor(ProductUom $uom): float
    {
        $factor = (float) $uom->conversion_factor;

        if ($factor <= 0) {
            throw ValidationException::withMessages([
                'lines' => ["Unit of measure #{$uom->id} has an invalid conversion factor."],
            ]);
        }

        return $factor;
    }

    /**
     * Per-base-unit price for a quantity priced in this UOM, rounded to the
     * storage scale (2dp). Non-divisible conversion factors can introduce
     * cent-level rounding — callers should prefer conversion factors that
     * divide the currency cleanly where exact totals are required.
     *
     * Mathematically identical to ProductUom::calculatePrice($basePrice) /
     * conversion_factor, but takes the already-resolved (e.g. tier) base-unit
     * price when the caller has one.
     */
    public function baseUnitPrice(Product $product, ProductUom $uom, ?float $baseUnitPrice = null): float
    {
        $factor = (float) $uom->conversion_factor;

        if ($factor <= 0) {
            throw ValidationException::withMessages([
                'lines' => ["Unit of measure #{$uom->id} has an invalid conversion factor."],
            ]);
        }

        $price = $baseUnitPrice ?? (float) $product->price;

        return round($price * (float) $uom->price_multiplier, 2);
    }
}
