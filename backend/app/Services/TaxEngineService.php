<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Setting;
use App\Models\Tax;

/**
 * Single runtime tax engine for sales, purchases, POS and commerce.
 *
 * Rates are configuration, not legal advice. Country defaults are only used
 * when the tenant has not configured a more specific Tax record.
 */
class TaxEngineService
{
    private const COUNTRY_DEFAULTS = [
        'SG' => [
            'regime' => 'GST',
            'currency' => 'SGD',
            'rates' => ['gst' => 9.0],
        ],
        'MY' => [
            'regime' => 'SST',
            'currency' => 'MYR',
            'rates' => ['sales' => 10.0, 'service' => 8.0],
        ],
    ];

    public function resolve(
        ?Product $product = null,
        ?string $jurisdiction = null,
        string $direction = 'output',
        ?string $taxType = null,
        ?string $date = null,
    ): array {
        $jurisdiction = strtoupper($jurisdiction ?? $this->tenantJurisdiction());
        $direction = $direction === 'input' ? 'input' : 'output';
        $date = $date ?? now()->toDateString();

        if (! $this->isEnabled()) {
            return $this->none($jurisdiction, 'Tax is disabled in tenant settings.');
        }

        if ($product && $product->tax_mode === 'custom' && $product->tax_rate !== null) {
            $rate = (float) $product->tax_rate;

            return [
                'rate' => $rate,
                'is_inclusive' => (bool) $product->tax_included,
                'tax_code' => 'PRODUCT-CUSTOM-'.rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.'),
                'tax_id' => null,
                'tax_name' => 'Product custom tax',
                'jurisdiction' => $jurisdiction,
                'regime' => self::COUNTRY_DEFAULTS[$jurisdiction]['regime'] ?? 'GST',
                'scope' => $direction,
                'treatment' => $rate > 0 ? 'standard_rated' : 'out_of_scope',
                'source' => 'product_custom',
            ];
        }

        $explicitTax = $product?->tax_id
            ? Tax::query()->whereKey($product->tax_id)->where('is_active', true)->first()
            : null;

        if ($explicitTax && $this->isValidOn($explicitTax, $date)) {
            return $this->fromTax($explicitTax, $jurisdiction, $direction, 'product');
        }

        $setting = Setting::getValue('tax', []);
        $defaultTax = ! empty($setting['default_tax_id'])
            ? Tax::query()->whereKey($setting['default_tax_id'])->where('is_active', true)->first()
            : null;
        // MRD R2.2: a default is direction-aware — an output (sales) default
        // must never auto-apply to purchase-side documents, and vice versa.
        if ($defaultTax && $this->isValidOn($defaultTax, $date) && $this->scopeMatches($defaultTax, $direction)) {
            return $this->fromTax($defaultTax, $jurisdiction, $direction, 'tenant_default');
        }

        $tax = Tax::query()
            ->where('is_active', true)
            ->where(function ($query) use ($jurisdiction) {
                $query->where('jurisdiction', $jurisdiction)->orWhereNull('jurisdiction');
            })
            ->where(function ($query) use ($direction) {
                $query->where('scope', $direction)->orWhere('scope', 'both')->orWhereNull('scope');
            })
            ->when($taxType, fn ($query) => $query->where(function ($q) use ($taxType) {
                $q->whereRaw('LOWER(type) = ?', [strtolower($taxType)])
                    ->orWhereRaw('LOWER(regime) = ?', [strtolower($taxType)]);
            }))
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_from')->orWhere('effective_from', '<=', $date);
            })
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            })
            ->orderByDesc('is_default')
            ->orderByDesc('effective_from')
            ->first();

        if ($tax) {
            return $this->fromTax($tax, $jurisdiction, $direction, 'localized_rule');
        }

        // Legacy product-level rate (products.tax_rate without tax_mode custom).
        // An explicit tax_mode of 'none' opts the product out of tax entirely.
        if ($product && $product->tax_mode !== 'none'
            && $product->tax_rate !== null && (float) $product->tax_rate > 0) {
            $rate = (float) $product->tax_rate;

            return [
                'rate' => $rate,
                'is_inclusive' => (bool) $product->tax_included,
                'tax_code' => 'PRODUCT-'.rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.'),
                'tax_id' => null,
                'tax_name' => 'Product tax',
                'jurisdiction' => $jurisdiction,
                'regime' => self::COUNTRY_DEFAULTS[$jurisdiction]['regime'] ?? 'GST',
                'scope' => $direction,
                'treatment' => 'standard_rated',
                'source' => 'product',
            ];
        }

        // MRD TAX-REQ-02 (D5): never silently apply a hardcoded country rate to
        // an unconfigured tenant. A document charges tax only when the tenant
        // has an explicit rule (or an explicit product-level configuration);
        // otherwise it resolves to no-tax with a deterministic warning.
        return $this->none($jurisdiction, 'No applicable tax rule was found. Configure a tax rule before charging tax in this jurisdiction/direction.');
    }

    /**
     * THE single tax arithmetic for the entire platform (MRD R3.1/R3.4):
     * exclusive, inclusive and line-discount handling live ONLY here.
     * Static so model/DTO layer math delegates instead of re-implementing.
     */
    public static function calculate(float $amount, float $rate, bool $inclusive, float $discount = 0.0): array
    {
        $amount = round(max($amount, 0.0), 2);
        $discount = round(min(max($discount, 0.0), $amount), 2);
        $grossOrNet = round($amount - $discount, 2);

        if ($rate <= 0) {
            return ['net' => $grossOrNet, 'tax' => 0.0, 'gross' => $grossOrNet];
        }

        if ($inclusive) {
            $net = round($grossOrNet / (1 + ($rate / 100)), 2);

            return ['net' => $net, 'tax' => round($grossOrNet - $net, 2), 'gross' => $grossOrNet];
        }

        $tax = round($grossOrNet * ($rate / 100), 2);

        return ['net' => $grossOrNet, 'tax' => $tax, 'gross' => round($grossOrNet + $tax, 2)];
    }

    public function countryDefaults(string $jurisdiction): array
    {
        return self::COUNTRY_DEFAULTS[strtoupper($jurisdiction)] ?? [
            'regime' => 'GST', 'currency' => null, 'rates' => [],
        ];
    }

    /**
     * Tenant home jurisdiction: explicit Setting tax.jurisdiction first, then
     * the jurisdiction of the tenant's default/active configured rule, then SG
     * as a label-only legacy default. This never charges a rate by itself.
     */
    public function tenantJurisdiction(): string
    {
        $fromSetting = strtoupper(trim((string) (Setting::getValue('tax', [])['jurisdiction'] ?? '')));

        if (preg_match('/^[A-Z]{2}$/', $fromSetting) === 1) {
            return $fromSetting;
        }

        $fromRules = Tax::query()
            ->where('is_active', true)
            ->whereNotNull('jurisdiction')
            ->orderByDesc('is_default')
            ->value('jurisdiction');

        if ($fromRules) {
            return strtoupper((string) $fromRules);
        }

        return 'SG';
    }

    private function fromTax(Tax $tax, string $jurisdiction, string $direction, string $source): array
    {
        $effectiveJurisdiction = strtoupper((string) ($tax->jurisdiction ?: $jurisdiction));
        $regime = strtoupper((string) ($tax->regime ?: $this->countryDefaults($effectiveJurisdiction)['regime']));

        return [
            'rate' => (float) $tax->rate,
            'is_inclusive' => (bool) $tax->is_inclusive,
            'tax_code' => $tax->code ?: $effectiveJurisdiction.'-'.$regime.'-'.rtrim(rtrim(number_format((float) $tax->rate, 2, '.', ''), '0'), '.'),
            'tax_id' => $tax->id,
            'tax_name' => $tax->name,
            'jurisdiction' => $effectiveJurisdiction,
            'regime' => $regime,
            'scope' => $tax->scope ?: $direction,
            'treatment' => $tax->treatment ?: ($tax->rate > 0 ? 'standard_rated' : 'zero_rated'),
            'source' => $source,
        ];
    }

    private function none(string $jurisdiction, string $reason): array
    {
        return [
            'rate' => 0.0, 'is_inclusive' => false, 'tax_code' => 'NO-TAX',
            'tax_id' => null, 'tax_name' => 'No Tax', 'jurisdiction' => $jurisdiction,
            'regime' => self::COUNTRY_DEFAULTS[$jurisdiction]['regime'] ?? 'GST',
            'scope' => 'both', 'treatment' => 'out_of_scope', 'source' => 'none', 'reason' => $reason,
        ];
    }

    private function isEnabled(): bool
    {
        return (bool) (Setting::getValue('tax', ['enabled' => true])['enabled'] ?? true);
    }

    private function isValidOn(Tax $tax, string $date): bool
    {
        return (! $tax->effective_from || $tax->effective_from->toDateString() <= $date)
            && (! $tax->effective_to || $tax->effective_to->toDateString() >= $date);
    }

    private function scopeMatches(Tax $tax, string $direction): bool
    {
        return ! $tax->scope
            || $tax->scope === 'both'
            || $tax->scope === $direction;
    }
}
