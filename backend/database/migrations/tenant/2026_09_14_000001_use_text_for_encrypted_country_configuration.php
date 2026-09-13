<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel's encrypted casts persist an opaque string, which is not a
        // JSON value. These columns were originally created as JSON before the
        // model gained encrypted:array casts.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE acct_country_configurations ALTER COLUMN api_credentials TYPE TEXT USING api_credentials::text');
            DB::statement('ALTER TABLE acct_country_configurations ALTER COLUMN settings TYPE TEXT USING settings::text');
        } elseif (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE acct_country_configurations MODIFY api_credentials TEXT NULL');
            DB::statement('ALTER TABLE acct_country_configurations MODIFY settings TEXT NULL');
        }
    }

    public function down(): void
    {
        // Encrypted ciphertext is intentionally not converted back to JSON.
    }
};
