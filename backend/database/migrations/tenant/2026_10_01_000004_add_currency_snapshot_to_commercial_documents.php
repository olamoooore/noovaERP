<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MRD pricelist-multicurrency-mrd FR-5.1 / FR-5.10 / FR-12.2 — document
 * currency + FX rate snapshot on every commercial document.
 *
 *   currency_code        — the currency the document is denominated in
 *   exchange_rate        — document currency → entity base, snapshotted at
 *                          creation/posting (immutable once posted, FR-9.6)
 *   base_currency_code   — snapshot of the effective entity base at creation
 *
 * Backfill (FR-5.10): every pre-feature row is restated in the tenant base
 * currency at rate 1.000000 — correct because historically all amounts were
 * base-denominated regardless of the currency label (G9). Idempotent (only
 * rows without a snapshot are touched) and reversible (columns dropped).
 *
 * payments additionally carry the settlement-date rate used for realized FX
 * (FR-7.2).
 */
return new class extends Migration
{
    /**
     * table => whether currency_code already exists on it.
     */
    private const DOCUMENTS = [
        'quotations' => false,
        'delivery_orders' => false,
        'sales_orders' => true,
        'invoices' => true,
        'customer_credit_notes' => true,
    ];

    public function up(): void
    {
        $base = "(SELECT base_currency FROM company_settings ORDER BY id LIMIT 1)";

        foreach (self::DOCUMENTS as $table => $hasCurrency) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $tbl) use ($table, $hasCurrency) {
                if (! $hasCurrency && ! Schema::hasColumn($table, 'currency_code')) {
                    $tbl->string('currency_code', 3)->nullable()->after('status');
                }
                if (! Schema::hasColumn($table, 'exchange_rate')) {
                    $tbl->decimal('exchange_rate', 15, 6)->nullable()->after('currency_code');
                }
                if (! Schema::hasColumn($table, 'base_currency_code')) {
                    $tbl->string('base_currency_code', 3)->nullable()->after('exchange_rate');
                }
            });

            // One-time restatement of historical rows: base currency, rate 1.
            $currencyArm = $hasCurrency
                ? "COALESCE(currency_code, {$base}, 'USD')"
                : "COALESCE({$base}, 'USD')";
            DB::statement("UPDATE {$table} SET currency_code = {$currencyArm} WHERE currency_code IS NULL");
            DB::statement("UPDATE {$table} SET base_currency_code = COALESCE(base_currency_code, {$base}, 'USD') WHERE exchange_rate IS NULL");
            DB::statement("UPDATE {$table} SET exchange_rate = 1.000000 WHERE exchange_rate IS NULL");
        }

        // Payments: settlement rate snapshot for realized FX (FR-7.2/7.3).
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $tbl) {
                if (! Schema::hasColumn('payments', 'exchange_rate')) {
                    $tbl->decimal('exchange_rate', 15, 6)->nullable()->after('currency');
                }
                if (! Schema::hasColumn('payments', 'base_currency_code')) {
                    $tbl->string('base_currency_code', 3)->nullable()->after('exchange_rate');
                }
            });

            DB::statement("UPDATE payments SET base_currency_code = COALESCE(base_currency_code, {$base}, 'USD'), exchange_rate = COALESCE(exchange_rate, 1.000000)");
        }
    }

    public function down(): void
    {
        $dropsets = [
            'quotations' => ['currency_code', 'exchange_rate', 'base_currency_code'],
            'delivery_orders' => ['currency_code', 'exchange_rate', 'base_currency_code'],
            'sales_orders' => ['exchange_rate', 'base_currency_code'],
            'invoices' => ['exchange_rate', 'base_currency_code'],
            'customer_credit_notes' => ['exchange_rate', 'base_currency_code'],
            'payments' => ['exchange_rate', 'base_currency_code'],
        ];

        foreach ($dropsets as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $present = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($table, $c)));
            if ($present === []) {
                continue;
            }
            Schema::table($table, function (Blueprint $tbl) use ($present) {
                $tbl->dropColumn($present);
            });
        }
    }
};
