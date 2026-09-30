<?php

use App\Models\CompanySettings;
use App\Models\CustomerPriceList;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MRD pricelist-multicurrency-mrd FR-12.1 / AC-6 — migrate the legacy
 * `customer_price_lists` into the general price-list model, one new list per
 * legacy record:
 *
 *   - per-product fixed price item → compute_type 'fixed' item (keeps
 *     min_quantity), evaluated first (lower sequence);
 *   - blanket percentage discount  → 'percentage' catch-all item
 *     (apply_on 'all', percent = discount_value);
 *   - blanket fixed-amount discount → 'formula' catch-all item with base
 *     'list_price' and fixed_discount = discount_value (exact reproduction of
 *     the legacy Customer::getProductPrice() arithmetic
 *     max(0, base − value)).
 *
 * The legacy tables are untouched (deprecation window); `legacy_price_list_id`
 * on price_lists is the bridge used by the customers FK swap and by down().
 * Idempotent: a second run is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_price_lists') || ! Schema::hasTable('price_lists')) {
            return;
        }

        $base = (function () {
            try {
                return CompanySettings::query()->value('base_currency') ?: 'USD';
            } catch (\Throwable) {
                return 'USD';
            }
        })();

        CustomerPriceList::with('items')->whereNotIn('id', function ($query) {
            $query->select('legacy_price_list_id')->from('price_lists')->whereNotNull('legacy_price_list_id');
        })->each(function (CustomerPriceList $legacy) use ($base) {
            $sequence = 10;
            $itemRows = [];

            // 1) per-product fixed prices — first-match order preserved.
            foreach ($legacy->items()->orderBy('id')->get() as $item) {
                $itemRows[] = [
                    'sequence' => $sequence,
                    'apply_on' => 'product',
                    'product_id' => $item->product_id,
                    'category_id' => null,
                    'min_quantity' => max(1, (int) ($item->min_quantity ?? 1)),
                    'max_quantity' => null,
                    'date_start' => null,
                    'date_end' => null,
                    'compute_type' => 'fixed',
                    'fixed_price' => $item->price,
                    'percent' => null,
                    'base' => null,
                    'other_price_list_id' => null,
                    'markup_percent' => null,
                    'fixed_discount' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $sequence += 10;
            }

            // 2) blanket discount — catch-all rule evaluated last.
            if ((float) $legacy->discount_value > 0) {
                if ($legacy->discount_type === 'percentage') {
                    $itemRows[] = [
                        'sequence' => $sequence,
                        'apply_on' => 'all',
                        'product_id' => null,
                        'category_id' => null,
                        'min_quantity' => 1,
                        'max_quantity' => null,
                        'date_start' => null,
                        'date_end' => null,
                        'compute_type' => 'percentage',
                        'fixed_price' => null,
                        'percent' => (float) $legacy->discount_value,
                        'base' => null,
                        'other_price_list_id' => null,
                        'markup_percent' => null,
                        'fixed_discount' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                } else {
                    $itemRows[] = [
                        'sequence' => $sequence,
                        'apply_on' => 'all',
                        'product_id' => null,
                        'category_id' => null,
                        'min_quantity' => 1,
                        'max_quantity' => null,
                        'date_start' => null,
                        'date_end' => null,
                        'compute_type' => 'formula',
                        'fixed_price' => null,
                        'percent' => null,
                        'base' => 'list_price',
                        'other_price_list_id' => null,
                        'markup_percent' => null,
                        'fixed_discount' => (float) $legacy->discount_value,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            $listId = DB::table('price_lists')->insertGetId([
                'name' => $legacy->name,
                'code' => 'LEGACY-'.$legacy->id,
                'currency_code' => $base,
                'description' => $legacy->description,
                'sequence' => 50,
                'valid_from' => $legacy->valid_from,
                'valid_until' => $legacy->valid_until,
                'is_active' => (bool) $legacy->is_active,
                'rounding_enabled' => false,
                'rounding_method' => 'nearest',
                'rounding_precision' => 0.01,
                'discount_display' => 'show_discounted_price',
                'legacy_price_list_id' => $legacy->id,
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($itemRows as $row) {
                DB::table('price_list_items')->insert($row + ['price_list_id' => $listId]);
            }

            // Every legacy list applied to its assigned customers only.
            $customers = DB::table('customers')->where('price_list_id', $legacy->id)->pluck('id');
            if ($customers->isEmpty()) {
                DB::table('price_list_applications')->insert([
                    'price_list_id' => $listId,
                    'applies_to_type' => 'all',
                    'applies_to_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                foreach ($customers as $customerId) {
                    DB::table('price_list_applications')->insert([
                        'price_list_id' => $listId,
                        'applies_to_type' => 'customer',
                        'applies_to_id' => $customerId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('price_lists')) {
            return;
        }

        $ids = DB::table('price_lists')->whereNotNull('legacy_price_list_id')->pluck('id')->all();
        if ($ids === []) {
            return;
        }

        DB::table('price_list_applications')->whereIn('price_list_id', $ids)->delete();
        DB::table('price_list_items')->whereIn('price_list_id', $ids)->delete();
        DB::table('price_lists')->whereIn('id', $ids)->delete();
    }
};
