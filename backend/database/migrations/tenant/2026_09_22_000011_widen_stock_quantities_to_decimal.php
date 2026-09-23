<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #250: stock quantities were integer columns while #135's UOM
 * conversion (conversion_factor decimal:6) and the goods-receipt path
 * (quantity_accepted decimal:15,4) already produce fractional base units. The
 * GL posted the full fractional value while stock truncated to a whole unit,
 * diverging the inventory subledger from the ledger.
 *
 * Widens every quantity column on the stock path to numeric(15,4), the scale
 * already used for quantity_accepted.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'stock_levels' => ['quantity', 'reserved_quantity'],
        'stock_movements' => ['quantity', 'balance_after', 'old_quantity'],
        'products' => ['stock_quantity'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $type = DB::selectOne(
                    'SELECT data_type FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
                    [$table, $column]
                )?->data_type;

                if ($type === 'numeric') {
                    continue;
                }

                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE numeric(15,4) USING {$column}::numeric");
            }
        }
    }

    public function down(): void
    {
        // Deliberately not reverted to integer: truncating existing fractional
        // quantities would corrupt stock data.
    }
};
