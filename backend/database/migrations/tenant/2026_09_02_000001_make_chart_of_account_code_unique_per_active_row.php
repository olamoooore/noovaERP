<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Allow a chart-of-account code to be reused after the previous account has
 * been soft deleted, while retaining uniqueness among active accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dropCodeUniqueness();

        DB::statement(
            'CREATE UNIQUE INDEX chart_of_accounts_code_unique '
            .'ON chart_of_accounts (code) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        $this->dropCodeUniqueness();

        DB::statement(
            'CREATE UNIQUE INDEX chart_of_accounts_code_unique '
            .'ON chart_of_accounts (code)'
        );
    }

    private function dropCodeUniqueness(): void
    {
        DB::statement(
            'ALTER TABLE chart_of_accounts '
            .'DROP CONSTRAINT IF EXISTS chart_of_accounts_code_unique'
        );
        DB::statement('DROP INDEX IF EXISTS chart_of_accounts_code_unique');
    }
};
