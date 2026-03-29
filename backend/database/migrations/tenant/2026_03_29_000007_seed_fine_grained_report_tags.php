<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Fine-grained cash flow tags and BS sub-tags needed by report services.
        // Parent IDs are resolved dynamically from the seeded tags in migration 000006.
        $cfOperatingId = DB::table('report_tags')->where('code', 'CF_OPERATING')->value('id');
        $cfInvestingId = DB::table('report_tags')->where('code', 'CF_INVESTING')->value('id');
        $cfFinancingId = DB::table('report_tags')->where('code', 'CF_FINANCING')->value('id');
        $bsCurrentAssetsId = DB::table('report_tags')->where('code', 'BS_CURRENT_ASSETS')->value('id');

        $tags = [
            // Balance Sheet sub-tags
            ['code' => 'BS_OTHER_ASSETS', 'name' => 'Other Assets', 'report_type' => 'balance_sheet', 'display_order' => 25, 'parent_id' => null],

            // Cash Flow — operating detail
            ['code' => 'CF_CASH', 'name' => 'Cash & Cash Equivalents', 'report_type' => 'cash_flow', 'display_order' => 11, 'parent_id' => $cfOperatingId],
            ['code' => 'CF_AR', 'name' => 'Accounts Receivable', 'report_type' => 'cash_flow', 'display_order' => 12, 'parent_id' => $cfOperatingId],
            ['code' => 'CF_INVENTORY', 'name' => 'Inventory', 'report_type' => 'cash_flow', 'display_order' => 13, 'parent_id' => $cfOperatingId],
            ['code' => 'CF_AP', 'name' => 'Accounts Payable', 'report_type' => 'cash_flow', 'display_order' => 14, 'parent_id' => $cfOperatingId],

            // Cash Flow — investing detail
            ['code' => 'CF_FIXED_ASSETS', 'name' => 'Fixed Assets', 'report_type' => 'cash_flow', 'display_order' => 21, 'parent_id' => $cfInvestingId],

            // Cash Flow — financing detail
            ['code' => 'CF_LONG_TERM_LIABILITIES', 'name' => 'Long-term Liabilities', 'report_type' => 'cash_flow', 'display_order' => 31, 'parent_id' => $cfFinancingId],
            ['code' => 'CF_CAPITAL', 'name' => 'Owner Capital', 'report_type' => 'cash_flow', 'display_order' => 32, 'parent_id' => $cfFinancingId],
            ['code' => 'CF_DRAWINGS', 'name' => 'Owner Drawings', 'report_type' => 'cash_flow', 'display_order' => 33, 'parent_id' => $cfFinancingId],
        ];

        foreach ($tags as &$tag) {
            $tag['created_at'] = $now;
            $tag['updated_at'] = $now;
        }

        DB::table('report_tags')->insert($tags);
    }

    public function down(): void
    {
        DB::table('report_tags')->whereIn('code', [
            'BS_OTHER_ASSETS',
            'CF_CASH', 'CF_AR', 'CF_INVENTORY', 'CF_AP',
            'CF_FIXED_ASSETS',
            'CF_LONG_TERM_LIABILITIES', 'CF_CAPITAL', 'CF_DRAWINGS',
        ])->delete();
    }
};
