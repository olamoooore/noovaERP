<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;

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
    public function __construct(private readonly TaxEngineService $engine) {}

    /**
     * Resolve tax information for a product/variant
     *
     * This is the canonical method for all transaction flows.
     * Returns tax rate, inclusive/exclusive mode, and tax code for GL/audit.
     *
     * G-28 (#274): callers pass the document's jurisdiction, direction and
     * date so date-effective / jurisdiction-specific rules resolve correctly;
     * a variant override — including an explicit 0% — always wins, and its
     * audit code uses the same canonical `{jurisdiction}-{regime}-{rate}`
     * format as the engine's synthetic codes (was `VARIANT-900`).
     *
     * @return array{rate: float, is_inclusive: bool, tax_code: string, tax_id: int|null, tax_name: string|null}
     */
    public function resolveTaxForProduct(
        Product $product,
        ?ProductVariant $variant = null,
        ?string $jurisdiction = null,
        string $direction = 'output',
        ?string $date = null,
    ): array {
        // Variant rates remain an explicit item override, then all other
        // resolution is delegated to the shared localized engine.
        if ($variant && $variant->tax_rate !== null) {
            $calculation = $this->engine->resolve($product, $jurisdiction, $direction, null, $date);
            $rate = (float) $variant->tax_rate;

            return [
                'rate' => $rate,
                'is_inclusive' => $calculation['is_inclusive'],
                'tax_code' => $this->canonicalCode($calculation, $rate),
                'tax_id' => null,
                'tax_name' => "Variant Tax {$variant->tax_rate}%",
            ];
        }

        $resolved = $this->engine->resolve($product, $jurisdiction, $direction, null, $date);

        return [
            'rate' => $resolved['rate'],
            'is_inclusive' => $resolved['is_inclusive'],
            'tax_code' => $resolved['tax_code'],
            'tax_id' => $resolved['tax_id'],
            'tax_name' => $resolved['tax_name'],
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
        return $this->engine->calculate($subtotal, $taxRate, $isInclusive);
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
     * G-28: canonical synthetic code for an override rate, in the engine's
     * `{jurisdiction}-{regime}-{rate}` format.
     */
    private function canonicalCode(array $calculation, float $rate): string
    {
        $jurisdiction = strtoupper((string) ($calculation['jurisdiction'] ?? ''));
        $regime = (string) ($calculation['regime'] ?? '');

        if ($jurisdiction === '' || $regime === '') {
            return $this->generateTaxCode('VARIANT', $rate);
        }

        $rateFormatted = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');

        return "{$jurisdiction}-{$regime}-{$rateFormatted}";
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
