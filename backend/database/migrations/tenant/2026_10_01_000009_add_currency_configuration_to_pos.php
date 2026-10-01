<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MRD pricelist-multicurrency FR-9.1 — POS terminal currency configuration
 * and per-order FX snapshots.
 *
 *   pos_terminals.default_currency    — the currency a terminal opens orders in;
 *   pos_terminals.allowed_currencies  — optional allow-list (JSON array);
 *   pos_orders.exchange_rate          — order currency → tenant base snapshot;
 *   pos_orders.base_currency_code     — snapshot of the base at order time.
 *
 * Backfill: existing POS orders were base-denominated regardless of the
 * historical `currency` label default (same G9 hygiene as the sales-side
 * migration 2026_10_01_000004) — restated to base at rate 1. Reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_terminals')) {
            Schema::table('pos_terminals', function (Blueprint $table) {
                if (! Schema::hasColumn('pos_terminals', 'default_currency')) {
                    $table->string('default_currency', 3)->nullable()->after('warehouse_id');
                }
                if (! Schema::hasColumn('pos_terminals', 'allowed_currencies')) {
                    $table->jsonb('allowed_currencies')->nullable()->after('default_currency');
                }
            });
        }

        if (! Schema::hasTable('pos_orders')) {
            return;
        }

        Schema::table('pos_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_orders', 'exchange_rate')) {
                $table->decimal('exchange_rate', 15, 6)->nullable()->after('currency');
            }
            if (! Schema::hasColumn('pos_orders', 'base_currency_code')) {
                $table->string('base_currency_code', 3)->nullable()->after('exchange_rate');
            }
        });

        $base = '(SELECT base_currency FROM company_settings ORDER BY id LIMIT 1)';

        DB::statement("UPDATE pos_orders SET base_currency_code = COALESCE(base_currency_code, {$base}, 'USD') WHERE exchange_rate IS NULL");
        DB::statement("UPDATE pos_orders SET exchange_rate = 1.000000 WHERE exchange_rate IS NULL");
        // Historical rows carried a default 'USD' label while their amounts
        // were base-denominated; restate truthfully.
        DB::statement("UPDATE pos_orders SET currency = COALESCE({$base}, 'USD') WHERE currency IS NULL OR exchange_rate = 1.000000");
    }

    public function down(): void
    {
        if (Schema::hasTable('pos_orders')) {
            $present = array_values(array_filter(
                ['exchange_rate', 'base_currency_code'],
                fn ($column) => Schema::hasColumn('pos_orders', $column)
            ));
            if ($present !== []) {
                Schema::table('pos_orders', function (Blueprint $table) use ($present) {
                    $table->dropColumn($present);
                });
            }
        }

        if (Schema::hasTable('pos_terminals')) {
            $present = array_values(array_filter(
                ['default_currency', 'allowed_currencies'],
                fn ($column) => Schema::hasColumn('pos_terminals', $column)
            ));
            if ($present !== []) {
                Schema::table('pos_terminals', function (Blueprint $table) use ($present) {
                    $table->dropColumn($present);
                });
            }
        }
    }
};