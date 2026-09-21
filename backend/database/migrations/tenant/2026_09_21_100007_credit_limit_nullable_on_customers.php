<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #144: customers.credit_limit was NOT NULL DEFAULT 0, so "not
 * configured" and "explicitly zero credit" were indistinguishable and
 * CreditCheckService folded both into "unlimited".
 *
 * Now:
 *   - NULL  => no credit control configured (unlimited) — explicit opt-out
 *   - 0     => zero credit; orders must be prepaid before confirmation
 *   - > 0   => normal credit limit
 *
 * Data decision: every historical row carries 0 because the column defaulted
 * to 0 and the model coerced null to 0 on save, so those rows cannot be
 * distinguished from a deliberate zero. They are migrated to NULL to preserve
 * the behaviour customers and orders have today; treating them as zero credit
 * is a business decision to be taken separately (see issue #144).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customers') || ! Schema::hasColumn('customers', 'credit_limit')) {
            return;
        }

        $schema = '"'.str_replace('"', '""', (string) DB::selectOne('SELECT current_schema() AS s')->s).'"';

        DB::statement("ALTER TABLE {$schema}.customers ALTER COLUMN credit_limit DROP DEFAULT");
        DB::statement("ALTER TABLE {$schema}.customers ALTER COLUMN credit_limit DROP NOT NULL");

        DB::table('customers')->where('credit_limit', 0)->update(['credit_limit' => null]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('customers') || ! Schema::hasColumn('customers', 'credit_limit')) {
            return;
        }

        $schema = '"'.str_replace('"', '""', (string) DB::selectOne('SELECT current_schema() AS s')->s).'"';

        DB::table('customers')->whereNull('credit_limit')->update(['credit_limit' => 0]);

        DB::statement("ALTER TABLE {$schema}.customers ALTER COLUMN credit_limit SET DEFAULT 0");
        DB::statement("ALTER TABLE {$schema}.customers ALTER COLUMN credit_limit SET NOT NULL");
    }
};
