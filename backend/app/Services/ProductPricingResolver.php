<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Carbon;

/**
 * Catalogue price authority (MRD pricelist-multicurrency FR-4.2 step 3).
 *
 * Wraps the live Issue #132 tier engine (Product::getPriceForQuantity) and the
 * raw list price so the two are never conflated:
 *
 *   list_price_raw — the product/variant sales price, the "based on: Sales
 *                    Price" value for pricelist percentage/formula rules
 *                    (Odoo semantics; reproduces the legacy
 *                    Customer::getProductPrice arithmetic exactly for AC-6);
 *   unit_price     — the tier-aware effective price for the quantity/customer,
 *                    the fallback authority when NO pricelist rule matches.
 *
 * Both are in the tenant/entity base currency (products carry no currency).
 */
class ProductPricingResolver
{
    /**
     * @return array{unit_price:float,list_price_raw:float,cost:float,source:string}
     */
    public function catalogBasePrice(
        Product $product,
        ?ProductVariant $variant,
        float $quantity,
        ?int $customerId = null,
        ?string $date = null,
    ): array {
        $raw = round((float) ($variant?->price ?? $product->price), 2);
        $cost = round((float) ($variant?->cost ?? $product->cost), 2);

        $tiered = $raw;

        try {
            $tiered = round((float) $product->getPriceForQuantity(
                (int) ceil(max(1.0, $quantity)),
                $customerId,
                $date ? Carbon::parse($date) : null,
                $variant?->id,
            ), 2);
        } catch (\Throwable) {
            // Tier resolution must never hard-fail a quote; raw price stands.
        }

        return [
            'unit_price' => $tiered,
            'list_price_raw' => $raw,
            'cost' => $cost,
            'source' => $tiered !== $raw ? 'tier' : 'catalog',
        ];
    }
}
