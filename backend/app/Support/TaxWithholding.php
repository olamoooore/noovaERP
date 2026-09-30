<?php

namespace App\Support;

use App\Models\Product;
use App\Models\Tax;

/**
 * MRD R4.5: a bill line is withholding when the product's DIRECTLY linked rule
 * is type 'withholding' (MY s.109B / SG s.45 style: tax deducted from what we
 * pay the vendor, then remitted to the authority). Withholding amounts are
 * DEDUCTIONS from the payable — never additive tax on top — at creation AND
 * posting, and they never appear on GST/SST returns.
 */
class TaxWithholding
{
    public static function lineIsWithholding(?int $productId): bool
    {
        if ($productId === null) {
            return false;
        }

        $taxId = Product::query()->whereKey($productId)->value('tax_id');

        if (! $taxId) {
            return false;
        }

        return Tax::query()->whereKey($taxId)->where('is_active', true)->value('type') === 'withholding';
    }

    /**
     * Split raw creation line-data tuples into (additive, withheld).
     *
     * @param  iterable<array{amount:float, product_id?:int|null}>  $pairs
     * @return array{0: float, 1: float}
     */
    public static function splitPairs(iterable $pairs): array
    {
        $additive = 0.0;
        $withheld = 0.0;

        foreach ($pairs as $pair) {
            $amount = round((float) ($pair['amount'] ?? 0), 2);

            if ($amount <= 0) {
                continue;
            }

            if (self::lineIsWithholding($pair['product_id'] ?? null)) {
                $withheld = round($withheld + $amount, 2);
            } else {
                $additive = round($additive + $amount, 2);
            }
        }

        return [$additive, $withheld];
    }

    /**
     * Split booked per-line tax amounts into (additive, withheld).
     *
     * @param  iterable<mixed>  $lines
     * @return array{0: float, 1: float}
     */
    public static function split(iterable $lines): array
    {
        $additive = 0.0;
        $withheld = 0.0;

        foreach ($lines as $line) {
            $amount = round((float) ($line->tax_amount ?? 0), 2);

            if ($amount <= 0) {
                continue;
            }

            if (self::lineIsWithholding($line->product_id ?? null)) {
                $withheld = round($withheld + $amount, 2);
            } else {
                $additive = round($additive + $amount, 2);
            }
        }

        return [$additive, $withheld];
    }
}
