<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('company_settings', 'default_bank_account_id')) {
            Schema::table('company_settings', function (Blueprint $table) {
                $table->foreignId('default_bank_account_id')
                    ->nullable()
                    ->after('default_payable_account_id')
                    ->constrained('chart_of_accounts');
            });
        }

        $mappedBankAccountId = DB::table('account_mappings')
            ->where('mapping_key', 'bank')
            ->whereNull('document_type')
            ->where('is_active', true)
            ->value('account_id');

        if ($mappedBankAccountId) {
            DB::table('company_settings')
                ->whereNull('default_bank_account_id')
                ->update(['default_bank_account_id' => $mappedBankAccountId]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('company_settings', 'default_bank_account_id')) {
            Schema::table('company_settings', function (Blueprint $table) {
                $table->dropConstrainedForeignId('default_bank_account_id');
            });
        }
    }
};
