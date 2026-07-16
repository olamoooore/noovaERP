<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Tax;

/**
 * TASK TAX-004: Canonical Tax Resolution Service
 *
 * This service is the ONLY authorized source for runtime tax rate resolution
 * across all transaction flows (POS, Ecommerce, Invoicing).
 *
 * DO NOT read Product.tax_rate or Product.effective_tax_rate directly in transaction flows.
 * DO NOT implement divergent tax logic in POS vs Ecommerce.
 *
 * See: backend/docs/modules/Tax/CANONICAL_TAX_SOURCE.md
 */
class TaxResolutionService
{
    /**
     * Resolve tax information for a product/variant
     *
     * This is the canonical method for all transaction flows.
     * Returns tax rate, inclusive/exclusive mode, and tax code for GL/audit.
     *
     * @return array{rate: float, is_inclusive: bool, tax_code: string, tax_id: int|null, tax_name: string|null}
     */
    public function resolveTaxForProduct(Product $product, ?ProductVariant $variant = null): array
    {
        // Priority 1: Variant-level tax rate (if variant provided and has tax_rate > 0)
        if ($variant && $variant->tax_rate !== null && (float) $variant->tax_rate > 0) {
            return [
                'rate' => (float) $variant->tax_rate,
                'is_inclusive' => $this->resolveIsInclusive($product, $variant),
                'tax_code' => $this->generateTaxCode('VARIANT', $variant->tax_rate),
                'tax_id' => null,
                'tax_name' => "Variant Tax {$variant->tax_rate}%",
            ];
        }

        // Priority 2: Product-level tax_rate (if set and > 0)
        if ($product->tax_rate !== null && (float) $product->tax_rate > 0) {
            return [
                'rate' => (float) $product->tax_rate,
                'is_inclusive' => $this->resolveIsInclusive($product, $variant),
                'tax_code' => $this->generateTaxCode('PRODUCT', $product->tax_rate),
                'tax_id' => null,
                'tax_name' => "Product Tax {$product->tax_rate}%",
            ];
        }

        // Priority 3: Product.tax_id → Tax model (if linked)
        if ($product->tax_id) {
            $productTax = Tax::query()->whereKey($product->tax_id)->first();
            if ($productTax && $productTax->is_active && $productTax->rate > 0) {
                return [
                    'rate' => (float) $productTax->rate,
                    'is_inclusive' => (bool) $productTax->is_inclusive,
                    'tax_code' => $productTax->code ?? $this->generateTaxCode('TAX', $productTax->rate),
                    'tax_id' => $productTax->id,
                    'tax_name' => $productTax->name,
                ];
            }
        }

        // Priority 4: System default tax (Setting['tax']['default_tax_id'])
        $taxSetting = Setting::getValue('tax', [
            'enabled' => true,
            'default_tax_id' => null,
        ]);

        if (! empty($taxSetting['enabled']) && ! empty($taxSetting['default_tax_id'])) {
            $defaultTax = Tax::query()->whereKey($taxSetting['default_tax_id'])->first();
            if ($defaultTax && $defaultTax->is_active && $defaultTax->rate > 0) {
                return [
                    'rate' => (float) $defaultTax->rate,
                    'is_inclusive' => (bool) $defaultTax->is_inclusive,
                    'tax_code' => $defaultTax->code ?? $this->generateTaxCode('DEFAULT', $defaultTax->rate),
                    'tax_id' => $defaultTax->id,
                    'tax_name' => $defaultTax->name,
                ];
            }
        }

        // Priority 5: Fallback - no tax
        return [
            'rate' => 0.0,
            'is_inclusive' => false,
            'tax_code' => 'NO-TAX',
            'tax_id' => null,
            'tax_name' => 'No Tax',
        ];
    }

    /**
     * Calculate tax amount for a given subtotal
     *
     * @param  float  $subtotal  Net amount (before tax for exclusive, gross for inclusive)
     * @param  float  $taxRate  Tax rate percentage (e.g., 10.00 for 10%)
     * @param  bool  $isInclusive  Whether tax is included in the subtotal
     * @return array{net: float, tax: float, gross: float}
     */
    public function calculateTax(float $subtotal, float $taxRate, bool $isInclusive): array
    {
        if ($taxRate <= 0) {
            return [
                'net' => round($subtotal, 2),
                'tax' => 0.0,
                'gross' => round($subtotal, 2),
            ];
        }

        if ($isInclusive) {
            // Tax is included in subtotal: extract net and tax from gross
            $gross = round($subtotal, 2);
            $divisor = 1 + ($taxRate / 100);
            $net = $divisor > 0 ? round($gross / $divisor, 2) : $gross;
            $tax = round($gross - $net, 2);

            return [
                'net' => $net,
                'tax' => $tax,
                'gross' => $gross,
            ];
        } else {
            // Tax is exclusive: add tax on top of net
            $net = round($subtotal, 2);
            $tax = round($net * ($taxRate / 100), 2);
            $gross = round($net + $tax, 2);

            return [
                'net' => $net,
                'tax' => $tax,
                'gross' => $gross,
            ];
        }
    }

    /**
     * Resolve whether tax is inclusive for a product/variant
     */
    private function resolveIsInclusive(Product $product, ?ProductVariant $variant = null): bool
    {
        // If product uses custom tax mode, use the product's tax_included setting
        if ($product->tax_mode === 'custom') {
            return (bool) $product->tax_included;
        }

        // If product is linked to a Tax model, use that tax's is_inclusive
        if ($product->tax_id) {
            $productTax = Tax::query()->whereKey($product->tax_id)->first();
            if ($productTax) {
                return (bool) $productTax->is_inclusive;
            }
        }

        // Fall back to default system tax from settings
        $taxSetting = Setting::getValue('tax', [
            'enabled' => true,
            'default_tax_id' => null,
        ]);

        if (! empty($taxSetting['enabled']) && ! empty($taxSetting['default_tax_id'])) {
            $defaultTax = Tax::query()->whereKey($taxSetting['default_tax_id'])->first();
            if ($defaultTax) {
                return (bool) $defaultTax->is_inclusive;
            }
        }

        // Default: exclusive (tax added on top)
        return false;
    }

    /**
     * Generate a tax code for audit trail when Tax model doesn't have a code
     *
     * @param  string  $source  Source of tax (PRODUCT, VARIANT, TAX, DEFAULT)
     * @param  float  $rate  Tax rate
     */
    private function generateTaxCode(string $source, float $rate): string
    {
        $rateFormatted = number_format($rate, 2, '', '');

        return "{$source}-{$rateFormatted}";
    }

    /**
     * Check if tax is enabled system-wide
     */
    public function isTaxEnabled(): bool
    {
        $taxSetting = Setting::getValue('tax', [
            'enabled' => true,
        ]);

        return (bool) ($taxSetting['enabled'] ?? true);
    }
}
