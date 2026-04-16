<?php

use App\Accounting\Services\AccountMappingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('chart_of_accounts') || !Schema::hasTable('account_mappings')) {
            return;
        }

        // Seed only missing defaults so existing tenant-specific mappings are preserved.
        app(AccountMappingService::class)->seedDefaults();
    }

    public function down(): void
    {
        // Intentionally left blank. This migration only backfills missing defaults.
    }
};
