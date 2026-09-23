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
        $schema = $this->currentSchema();

        $this->dropCodeUniqueness();

        DB::statement(
            "CREATE UNIQUE INDEX chart_of_accounts_code_unique "
            ."ON \"{$schema}\".chart_of_accounts (code) WHERE deleted_at IS NULL"
        );
    }

    public function down(): void
    {
        $schema = $this->currentSchema();

        $this->dropCodeUniqueness();

        DB::statement(
            "CREATE UNIQUE INDEX chart_of_accounts_code_unique "
            ."ON \"{$schema}\".chart_of_accounts (code)"
        );
    }

    /**
     * Every statement is schema-qualified: the tenant-migration search_path
     * falls back to public, so unqualified DDL can leak into central tables
     * once the tenant-schema object has already been dropped.
     */
    private function dropCodeUniqueness(): void
    {
        $schema = $this->currentSchema();

        DB::statement(
            "ALTER TABLE \"{$schema}\".chart_of_accounts "
            .'DROP CONSTRAINT IF EXISTS chart_of_accounts_code_unique'
        );
        DB::statement("DROP INDEX IF EXISTS \"{$schema}\".chart_of_accounts_code_unique");
    }

    private function currentSchema(): string
    {
        return DB::selectOne('SELECT current_schema() AS s')->s;
    }
};
