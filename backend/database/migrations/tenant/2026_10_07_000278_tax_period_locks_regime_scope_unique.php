<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #278 F4: a lock is unique per tenant + period + legal entity + REGIME, so
 * the same entity can hold separate SG and MY locks for one month (filing
 * one regime's return must not need, or block, the other's lock).
 *
 * Index-only change: every existing row already satisfies the wider key
 * (it only adds a column to the old unique key), so no data is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_period_locks', function (Blueprint $table) {
            $table->dropUnique('tax_period_locks_scope_unique');
            $table->unique(['tenant_id', 'period', 'legal_entity_id', 'regime'], 'tax_period_locks_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tax_period_locks', function (Blueprint $table) {
            $table->dropUnique('tax_period_locks_scope_unique');
            $table->unique(['tenant_id', 'period', 'legal_entity_id'], 'tax_period_locks_scope_unique');
        });
    }
};
