<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #899 (SALES-PLAT / PC5): every sales document number must be protected
 * by a unique index, so even a numbering regression can only fail loudly, not
 * issue duplicate documents.
 *
 * The indexes are created idempotently and only after checking for legacy
 * duplicates: if two live or soft-deleted documents already share a number the
 * migration fails with the list of offenders instead of deleting or silently
 * skipping anything.
 */
return new class extends Migration
{
    private const NUMBER_COLUMNS = [
        'invoices' => 'invoice_number',
        'quotations' => 'quotation_number',
        'sales_orders' => 'sales_order_number',
        'delivery_orders' => 'delivery_order_number',
        'sales_blanket_orders' => 'blanket_number',
        'customer_credit_notes' => 'credit_note_number',
    ];

    public function up(): void
    {
        foreach (self::NUMBER_COLUMNS as $table => $column) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $duplicates = DB::table($table)
                ->select($column, DB::raw('COUNT(*) as occurrences'))
                ->whereNotNull($column)
                ->groupBy($column)
                ->havingRaw('COUNT(*) > 1')
                ->orderByDesc('occurrences')
                ->limit(20)
                ->get();

            if ($duplicates->isNotEmpty()) {
                $detail = $duplicates->map(fn ($row): string => sprintf(
                    '"%s" (%d copies)',
                    $row->{$column},
                    $row->occurrences,
                ))->implode(', ');

                throw new \RuntimeException(
                    "Cannot enforce a unique index on {$table}.{$column}: duplicate document numbers exist: {$detail}. "
                    .'Renumber or merge these documents, then re-run the migration. No data was changed.'
                );
            }

            DB::statement(sprintf(
                'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (%s)',
                $table.'_'.$column.'_unique',
                $table,
                $column,
            ));
        }
    }

    public function down(): void
    {
        // The unique indexes were created (with these names) by the original
        // CREATE TABLE migrations; they are schema guarantees this migration
        // only re-asserts. Dropping them here would silently weaken the
        // schema on rollback, so down() deliberately leaves them in place.
    }
};
