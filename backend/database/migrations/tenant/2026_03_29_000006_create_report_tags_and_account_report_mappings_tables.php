<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_tags', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->enum('report_type', ['balance_sheet', 'profit_loss', 'cash_flow', 'management']);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->foreignId('parent_id')->nullable()->constrained('report_tags')->onDelete('cascade');
            $table->timestamps();
        });

        Schema::create('account_report_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('master_coa_node_id')->constrained('master_coa_nodes')->onDelete('cascade');
            $table->foreignId('report_tag_id')->constrained('report_tags')->onDelete('cascade');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['master_coa_node_id', 'report_tag_id']);
        });

        // Seed standard BS / P&L / Cash Flow report tags
        $now = now();
        $tags = [
            // Balance Sheet
            ['code' => 'BS_CURRENT_ASSETS', 'name' => 'Current Assets', 'report_type' => 'balance_sheet', 'display_order' => 10, 'parent_id' => null],
            ['code' => 'BS_NON_CURRENT_ASSETS', 'name' => 'Non-Current Assets', 'report_type' => 'balance_sheet', 'display_order' => 20, 'parent_id' => null],
            ['code' => 'BS_CURRENT_LIABILITIES', 'name' => 'Current Liabilities', 'report_type' => 'balance_sheet', 'display_order' => 30, 'parent_id' => null],
            ['code' => 'BS_NON_CURRENT_LIABILITIES', 'name' => 'Non-Current Liabilities', 'report_type' => 'balance_sheet', 'display_order' => 40, 'parent_id' => null],
            ['code' => 'BS_EQUITY', 'name' => 'Equity', 'report_type' => 'balance_sheet', 'display_order' => 50, 'parent_id' => null],

            // Profit & Loss
            ['code' => 'PL_REVENUE', 'name' => 'Revenue', 'report_type' => 'profit_loss', 'display_order' => 10, 'parent_id' => null],
            ['code' => 'PL_COGS', 'name' => 'Cost of Goods Sold', 'report_type' => 'profit_loss', 'display_order' => 20, 'parent_id' => null],
            ['code' => 'PL_OPERATING_EXPENSES', 'name' => 'Operating Expenses', 'report_type' => 'profit_loss', 'display_order' => 30, 'parent_id' => null],
            ['code' => 'PL_OTHER_INCOME', 'name' => 'Other Income', 'report_type' => 'profit_loss', 'display_order' => 40, 'parent_id' => null],
            ['code' => 'PL_OTHER_EXPENSES', 'name' => 'Other Expenses', 'report_type' => 'profit_loss', 'display_order' => 50, 'parent_id' => null],
            ['code' => 'PL_TAX_EXPENSE', 'name' => 'Tax Expense', 'report_type' => 'profit_loss', 'display_order' => 60, 'parent_id' => null],

            // Cash Flow (indirect method)
            ['code' => 'CF_OPERATING', 'name' => 'Operating Activities', 'report_type' => 'cash_flow', 'display_order' => 10, 'parent_id' => null],
            ['code' => 'CF_INVESTING', 'name' => 'Investing Activities', 'report_type' => 'cash_flow', 'display_order' => 20, 'parent_id' => null],
            ['code' => 'CF_FINANCING', 'name' => 'Financing Activities', 'report_type' => 'cash_flow', 'display_order' => 30, 'parent_id' => null],
        ];

        foreach ($tags as &$tag) {
            $tag['created_at'] = $now;
            $tag['updated_at'] = $now;
        }

        DB::table('report_tags')->insert($tags);
    }

    public function down(): void
    {
        Schema::dropIfExists('account_report_mappings');
        Schema::dropIfExists('report_tags');
    }
};
