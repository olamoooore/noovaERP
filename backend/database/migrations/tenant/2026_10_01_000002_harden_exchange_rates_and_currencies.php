<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MRD pricelist-multicurrency-mrd FR-1 — exchange-rate integrity & currency
 * sellability hardening.
 *
 * 1. `currencies.is_sellable` (FR-1.1): only sellable, active currencies may
 *    back documents and price lists. Default true keeps every existing tenant
 *    behaving exactly as before (backward compatibility §13).
 * 2. Legacy NULL `exchange_rates.source` rows are normalised to 'manual' —
 *    the read-side tie-break (FR-1.4: manual wins for the same date) needs a
 *    deterministic source column, and free-NULL rows were historically
 *    hand-entered.
 * 3. Duplicate pair+date sweep, then partial unique indexes so at most ONE
 *    manual row and at most ONE auto (api/bank/…) row can exist per pair per
 *    date (FR-1.4 determinism at the storage level).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('currencies', 'is_sellable')) {
            return;
        }

        Schema::table('currencies', function (Blueprint $table) {
            $table->boolean('is_sellable')->default(true)->after('is_active');
        });

        DB::statement("UPDATE currencies SET is_sellable = true WHERE is_sellable IS NULL");

        // ── exchange_rates hardening ─────────────────────────────────────
        DB::statement("UPDATE exchange_rates SET source = 'manual' WHERE source IS NULL");

        // Deterministic dedupe: per (pair, date, is-manual) keep the newest id.
        DB::statement(<<<'SQL'
            DELETE FROM exchange_rates
            WHERE id IN (
                SELECT e.id FROM exchange_rates e
                JOIN (
                    SELECT from_currency, to_currency, effective_date,
                           (source = 'manual') AS is_manual,
                           MAX(id) AS keep_id
                    FROM exchange_rates
                    GROUP BY from_currency, to_currency, effective_date, (source = 'manual')
                ) k ON k.from_currency = e.from_currency
                   AND k.to_currency = e.to_currency
                   AND k.effective_date = e.effective_date
                   AND (e.source = 'manual') = k.is_manual
                WHERE e.id <> k.keep_id
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS exchange_rates_pair_date_manual_uidx
            ON exchange_rates (from_currency, to_currency, effective_date)
            WHERE source = 'manual'
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS exchange_rates_pair_date_auto_uidx
            ON exchange_rates (from_currency, to_currency, effective_date)
            WHERE source <> 'manual'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS exchange_rates_pair_date_manual_uidx');
        DB::statement('DROP INDEX IF EXISTS exchange_rates_pair_date_auto_uidx');

        if (Schema::hasColumn('currencies', 'is_sellable')) {
            Schema::table('currencies', function (Blueprint $table) {
                $table->dropColumn('is_sellable');
            });
        }
    }
};
