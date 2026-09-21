<?php

namespace App\Services;

use App\Models\Product;

/**
 * Issue #130: a bundle/kit sale consumes its COMPONENT products, not the bundle
 * product row. Nothing in POS/Ecommerce/Sales decomposed bundles, so component
 * stock never moved and bundle COGS was either zero or a hand-maintained field.
 *
 * This is the single shared decomposition point: stock-deduction and COGS
 * paths call it instead of each reimplementing bundle awareness.
 */
class BundleDecompositionService
{
    /**
     * Expand a sold product + quantity into the component lines it consumes.
     * A non-bundle product returns itself (unchanged behaviour).
     *
     * Only components that track inventory are returned — a bundle may contain
     * service/non-stock items.
     *
     * @return array<int, array{product: Product, quantity: float}>
     */
    public function stockConsumingComponents(Product $product, float $quantity): array
    {
        if ($quantity <= 0) {
            return [];
        }

        if (! $product->is_bundle) {
            return (bool) ($product->track_inventory ?? false)
                ? [['product' => $product, 'quantity' => $quantity]]
                : [];
        }

        $product->loadMissing('bundleComponents.componentProduct');

        $components = [];

        foreach ($product->bundleComponents as $component) {
            $componentProduct = $component->componentProduct;

            if (! $componentProduct || ! (bool) ($componentProduct->track_inventory ?? false)) {
                continue;
            }

            $components[] = [
                'product' => $componentProduct,
                'quantity' => (float) $component->quantity * $quantity,
            ];
        }

        return $components;
    }

    /**
     * COGS for the line: the sum of the components' costs (or the product's own
     * cost for a non-bundle). Returns 0 when nothing is stock-tracked.
     */
    public function cost(Product $product, float $quantity): float
    {
        if ($quantity <= 0) {
            return 0.0;
        }

        if (! $product->is_bundle) {
            return (float) ($product->cost ?? 0) * $quantity;
        }

        $product->loadMissing('bundleComponents.componentProduct');

        $cost = 0.0;

        foreach ($product->bundleComponents as $component) {
            $componentProduct = $component->componentProduct;

            if (! $componentProduct) {
                continue;
            }

            $cost += (float) ($componentProduct->cost ?? 0) * (float) $component->quantity * $quantity;
        }

        return round($cost, 2);
    }
}
