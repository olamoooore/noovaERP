<?php

namespace Modules\FinancialReporting\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now();
        $userId = DB::table('users')->first()->id ?? 1;

        $templates = [
            // Financial Reports
            [
                'name' => 'Monthly Profit & Loss',
                'description' => 'Profit and Loss statement for the current month',
                'category' => 'financial',
                'report_type' => 'profit-loss',
                'default_filters' => json_encode([
                    'start_date' => date('Y-m-01'),
                    'end_date' => date('Y-m-t'),
                    'interval' => 'month',
                ]),
                'is_system' => true,
                'is_public' => true,
                'icon' => 'solar:chart-2-bold-duotone',
                'color' => 'blue',
                'sort_order' => 1,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Quarterly Profit & Loss',
                'description' => 'Profit and Loss statement for the current quarter',
                'category' => 'financial',
                'report_type' => 'profit-loss',
                'default_filters' => json_encode([
                    'start_date' => date('Y-m-01', strtotime('first day of -2 months')),
                    'end_date' => date('Y-m-t'),
                    'interval' => 'quarter',
                ]),
                'is_system' => true,
                'is_public' => true,
                'icon' => 'solar:chart-2-bold-duotone',
                'color' => 'blue',
                'sort_order' => 2,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Year-to-Date Profit & Loss',
                'description' => 'Profit and Loss statement from start of year to today',
                'category' => 'financial',
                'report_type' => 'profit-loss',
                'default_filters' => json_encode([
                    'start_date' => date('Y-01-01'),
                    'end_date' => date('Y-m-d'),
                    'interval' => 'year',
                ]),
                'is_system' => true,
                'is_public' => true,
                'icon' => 'solar:chart-2-bold-duotone',
                'color' => 'blue',
                'sort_order' => 3,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Current Balance Sheet',
                'description' => 'Balance Sheet as of today',
                'category' => 'financial',
                'report_type' => 'balance-sheet',
                'default_filters' => json_encode([
                    'as_of_date' => date('Y-m-d'),
                ]),
                'is_system' => true,
                'is_public' => true,
                'icon' => 'solar:document-text-bold-duotone',
                'color' => 'green',
                'sort_order' => 4,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Monthly Cash Flow',
                'description' => 'Cash Flow statement for the current month',
                'category' => 'financial',
                'report_type' => 'cash-flow',
                'default_filters' => json_encode([
                    'start_date' => date('Y-m-01'),
                    'end_date' => date('Y-m-t'),
                    'method' => 'indirect',
                ]),
                'is_system' => true,
                'is_public' => true,
                'icon' => 'solar:wallet-money-bold-duotone',
                'color' => 'purple',
                'sort_order' => 5,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Current Trial Balance',
                'description' => 'Trial Balance as of today',
                'category' => 'financial',
                'report_type' => 'trial-balance',
                'default_filters' => json_encode([
                    'as_of_date' => date('Y-m-d'),
                ]),
                'is_system' => true,
                'is_public' => true,
                'icon' => 'solar:calculator-bold-duotone',
                'color' => 'gray',
                'sort_order' => 6,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ],

            // Inventory Reports
            [
                'name' => 'Current Stock Valuation',
                'description' => 'Stock valuation as of today',
                'category' => 'inventory',
                'report_type' => 'stock-valuation',
                'default_filters' => json_encode([
                    'as_of_date' => date('Y-m-d'),
                ]),
                'is_system' => true,
                'is_public' => true,
                'icon' => 'solar:box-bold-duotone',
                'color' => 'orange',
                'sort_order' => 10,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Monthly Stock Movement',
                'description' => 'Stock movements for the current month',
                'category' => 'inventory',
                'report_type' => 'stock-movement',
                'default_filters' => json_encode([
                    'start_date' => date('Y-m-01'),
                    'end_date' => date('Y-m-t'),
                ]),
                'is_system' => true,
                'is_public' => true,
                'icon' => 'solar:transfer-horizontal-bold-duotone',
                'color' => 'orange',
                'sort_order' => 11,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Reorder Report',
                'description' => 'Products below reorder level',
                'category' => 'inventory',
                'report_type' => 'reorder',
                'default_filters' => json_encode([]),
                'is_system' => true,
                'is_public' => true,
                'icon' => 'solar:cart-plus-bold-duotone',
                'color' => 'red',
                'sort_order' => 12,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ],

            // Sales Analytics
            [
                'name' => 'Monthly Sales by Product',
                'description' => 'Sales breakdown by product for current month',
                'category' => 'sales',
                'report_type' => 'sales-by-product',
                'default_filters' => json_encode([
                    'start_date' => date('Y-m-01'),
                    'end_date' => date('Y-m-t'),
                ]),
                'is_system' => true,
                'is_public' => true,
                'icon' => 'solar:tag-price-bold-duotone',
                'color' => 'teal',
                'sort_order' => 20,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Sales Trends (Last 12 Months)',
                'description' => 'Monthly sales trends for the past year',
                'category' => 'sales',
                'report_type' => 'sales-trends',
                'default_filters' => json_encode([
                    'start_date' => date('Y-m-d', strtotime('-12 months')),
                    'end_date' => date('Y-m-d'),
                    'interval' => 'month',
                ]),
                'is_system' => true,
                'is_public' => true,
                'icon' => 'solar:graph-up-bold-duotone',
                'color' => 'teal',
                'sort_order' => 21,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('report_templates')->insert($templates);
    }
}
