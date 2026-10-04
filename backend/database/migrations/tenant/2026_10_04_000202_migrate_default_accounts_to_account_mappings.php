<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #877 (SA3): Settings > Default Accounts wrote company_settings.default_*
 * account columns, but posting resolves accounts through account_mappings
 * (AccountMappingService). A configured account therefore never reached a
 * posting while the UI reported success.
 *
 * This migration backfills each legacy column into its account-role mapping
 * (the explicitly configured value wins over a lazily auto-seeded generic
 * mapping), then clears the legacy columns so they cannot shadow the mapping
 * again.
 */
return new class extends Migration
{
    private const COLUMN_TO_MAPPING_KEY = [
        'default_receivable_account_id' => 'accounts_receivable',
        'default_payable_account_id' => 'accounts_payable',
        'default_bank_account_id' => 'bank',
        'default_revenue_account_id' => 'sales_revenue',
        'shipping_revenue_account_id' => 'shipping_revenue',
        'default_tax_account_id' => 'sales_tax_payable',
        'default_expense_account_id' => 'general_expense',
        'default_purchase_tax_account_id' => 'input_tax',
        'default_inventory_account_id' => 'inventory',
        'default_cogs_account_id' => 'cogs',
        'default_retained_earnings_account_id' => 'retained_earnings',
        'default_bank_charges_account_id' => 'bank_charges',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('company_settings')
            || ! Schema::hasTable('account_mappings')
            || ! Schema::hasTable('chart_of_accounts')) {
            return;
        }

        $settings = DB::table('company_settings')->first();

        if ($settings) {
            $clearedColumns = [];

            foreach (self::COLUMN_TO_MAPPING_KEY as $column => $mappingKey) {
                if (! Schema::hasColumn('company_settings', $column)) {
                    continue;
                }

                $accountId = $settings->{$column} ?? null;

                if (! $accountId) {
                    $clearedColumns[$column] = null;

                    continue;
                }

                // Never point a mapping at a missing account (the column has
                // an FK, but guard against a partially-restored schema). Leave
                // the value in place if it cannot be backfilled.
                if (! DB::table('chart_of_accounts')->where('id', $accountId)->exists()) {
                    continue;
                }

                $existing = DB::table('account_mappings')
                    ->where('mapping_key', $mappingKey)
                    ->whereNull('document_type')
                    ->first();

                if ($existing) {
                    DB::table('account_mappings')->where('id', $existing->id)->update([
                        'account_id' => $accountId,
                        'is_active' => true,
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('account_mappings')->insert([
                        'mapping_key' => $mappingKey,
                        'account_id' => $accountId,
                        'document_type' => null,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $clearedColumns[$column] = null;
            }

            if ($clearedColumns !== []) {
                DB::table('company_settings')->update($clearedColumns);
            }
        }
    }

    public function down(): void
    {
        // Forward-only: account_mappings is the single source of truth and the
        // legacy columns stay empty. Restoring the pre-migration values would
        // re-introduce the shadowing bug this migration fixed.
    }
};
