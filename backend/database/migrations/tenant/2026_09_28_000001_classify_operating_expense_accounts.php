<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Classify imported operating-expense accounts so they remain reportable
     * before a tenant configures the optional master-COA mapping hierarchy.
     */
    public function up(): void
    {
        DB::table('chart_of_accounts')
            ->whereIn('code', ['611000', '657000'])
            ->where('type', 'expense')
            ->whereNull('subtype')
            ->update([
                'subtype' => 'operating_expense',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Keep the classification on rollback: an existing tenant may have
        // independently assigned the same valid subtype after this migration.
    }
};
