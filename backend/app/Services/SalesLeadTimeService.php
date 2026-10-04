<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #408 (SALES-ODOO): computes the expected (scheduled) date for a sales
 * document from its product lead times. NoovaERP has no product-level lead-time
 * column; the real data lives on the product↔supplier link
 * (product_suppliers.lead_time_days, maintained by procurement) with the
 * supplier's default_lead_time_days as fallback. The expected date is the
 * document date plus the maximum lead time across the deliverable product
 * lines; when no line has any lead-time data the date is left null (nothing is
 * invented).
 */
class SalesLeadTimeService
{
    /** @var array<int, int|null> per-request product lead-time cache */
    private array $cache = [];

    /**
     * Longest lead time across the given products, or null when no product has
     * supplier lead-time data.
     *
     * @param  iterable<int|string|null>  $productIds
     */
    public function maxLeadTimeDays(iterable $productIds): ?int
    {
        $ids = [];
        foreach ($productIds as $productId) {
            if ($productId !== null && $productId !== '') {
                $ids[(int) $productId] = (int) $productId;
            }
        }

        $max = null;
        foreach ($ids as $productId) {
            $days = $this->leadTimeDaysForProduct($productId);
            if ($days !== null) {
                $max = $max === null ? $days : max($max, $days);
            }
        }

        return $max;
    }

    /**
     * Lead time for one product: its active product_suppliers link, falling
     * back to the linked supplier's default. Null when the product has no
     * supplier link or the procurement tables are not present.
     */
    public function leadTimeDaysForProduct(int $productId): ?int
    {
        if (array_key_exists($productId, $this->cache)) {
            return $this->cache[$productId];
        }

        if (! Schema::hasTable('product_suppliers') || ! Schema::hasTable('suppliers')) {
            return $this->cache[$productId] = null;
        }

        $row = DB::table('product_suppliers')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'product_suppliers.supplier_id')
            ->where('product_suppliers.product_id', $productId)
            ->where('product_suppliers.is_active', true)
            ->selectRaw('MAX(COALESCE(product_suppliers.lead_time_days, suppliers.default_lead_time_days)) AS lead_time_days')
            ->first();

        $days = $row?->lead_time_days;

        return $this->cache[$productId] = ($days === null || (int) $days <= 0) ? null : (int) $days;
    }

    /**
     * Expected date = base date + longest product lead time. Null when there is
     * no lead-time data to compute from.
     *
     * @param  iterable<int|string|null>  $productIds
     */
    public function expectedDate(iterable $productIds, CarbonInterface|string|null $baseDate = null): ?CarbonInterface
    {
        $days = $this->maxLeadTimeDays($productIds);

        if ($days === null) {
            return null;
        }

        return Carbon::parse($baseDate ?? now()->toDateString())->startOfDay()->addDays($days);
    }
}
