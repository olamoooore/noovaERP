<?php

namespace App\Support;

use App\Models\Vendor;
use Modules\Contact\src\Models\ContactTaxProfile;

/**
 * MRD R4.6 / #270: the single consultation point for vendor tax profiles.
 *
 * Vendor-specific withholding rate overrides, exemption and TIN warnings are
 * resolved here (shared by every purchase-side path) — never duplicated.
 */
class VendorTaxProfileResolver
{
    /** @var array<int, ContactTaxProfile|null> */
    private static array $cache = [];

    public static function forVendor(?int $vendorId): ?ContactTaxProfile
    {
        if (! $vendorId) {
            return null;
        }

        if (array_key_exists($vendorId, self::$cache)) {
            return self::$cache[$vendorId];
        }

        $vendor = Vendor::withTrashed()->find($vendorId);

        if (! $vendor || empty($vendor->core_contact_uuid)) {
            return self::$cache[$vendorId] = null;
        }

        return self::$cache[$vendorId] = ContactTaxProfile::query()
            ->where('contact_id', $vendor->core_contact_uuid)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Resolve the effective withholding rate for a line, consulting the
     * vendor profile when the line's product rule is withholding-typed.
     *
     * Rules: exempt profile → 0.0 (no deduction); explicit
     * withholding_tax_rate → override; otherwise the line rate is kept.
     * Warnings: vendor_tax_profile_missing / vendor_tin_missing.
     */
    public static function resolveWithholdingRate(
        ?int $vendorId,
        ?int $productId,
        float $lineRate,
        ?array &$warnings = null,
    ): float {
        if (! $vendorId || ! $productId || $lineRate <= 0) {
            return $lineRate;
        }

        if (! self::productRuleIsWithholding($productId)) {
            return $lineRate;
        }

        $profile = self::forVendor($vendorId);

        if (! $profile) {
            $warnings[] = 'vendor_tax_profile_missing';

            return $lineRate;
        }

        if (empty($profile->tax_id)) {
            $warnings[] = 'vendor_tin_missing';
        }

        if ($profile->is_tax_exempt) {
            return 0.0;
        }

        if ($profile->withholding_tax_rate !== null) {
            return (float) $profile->withholding_tax_rate;
        }

        return $lineRate;
    }

    private static function productRuleIsWithholding(int $productId): bool
    {
        $taxId = \App\Models\Product::query()->whereKey($productId)->value('tax_id');

        return $taxId
            ? \App\Models\Tax::query()->whereKey($taxId)->where('type', 'withholding')->exists()
            : false;
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
