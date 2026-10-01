<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MRD pricelist-multicurrency FR-7.7 (exposure report) — the AP side needs
 * the same document-currency snapshot as the sales side: `bills.currency_code`
 * existed since 2026_09_21, but without `exchange_rate` a foreign vendor bill
 * could only be reported at a fabricated 1:1 base equivalent.
 *
 * Additive + reversible; historical rows backfill to base rate 1 exactly like
 * the sales-side migration 2026_10_01_000004 (their amounts were
 * base-denominated at the time).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bills')) {
            return;
        }

        Schema::table('bills', function (Blueprint $table) {
            if (! Schema::hasColumn('bills', 'exchange_rate')) {
                $table->decimal('exchange_rate', 15, 6)->nullable()->after('currency_code');
            }
            if (! Schema::hasColumn('bills', 'base_currency_code')) {
                $table->string('base_currency_code', 3)->nullable()->after('exchange_rate');
            }
        });

        $base = '(SELECT base_currency FROM company_settings ORDER BY id LIMIT 1)';

        if (Schema::hasColumn('bills', 'currency_code')) {
            DB::statement("UPDATE bills SET currency_code = COALESCE(currency_code, {$base}, 'USD') WHERE currency_code IS NULL");
        }
        DB::statement("UPDATE bills SET base_currency_code = COALESCE(base_currency_code, {$base}, 'USD') WHERE base_currency_code IS NULL");
        DB::statement('UPDATE bills SET exchange_rate = 1.000000 WHERE exchange_rate IS NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('bills')) {
            return;
        }

        $present = array_values(array_filter(
            ['exchange_rate', 'base_currency_code'],
            fn ($column) => Schema::hasColumn('bills', $column)
        ));

        if ($present !== []) {
            Schema::table('bills', function (Blueprint $table) use ($present) {
                $table->dropColumn($present);
            });
        }
    }
};
