<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Issue #332: a sales line can split its revenue across several analytic
     * accounts by percentage — [{analytic_account_id, percentage}, …] summing
     * to 100. The distribution travels quotation → sales order → invoice and
     * posting turns each share into its own journal line on the existing
     * department_id / project_id / location_id columns (no new ledger store).
     *
     * Additive only: the existing single project_id / department_id line
     * columns and the free-text invoice/quotation cost_center_id header stay
     * untouched — no data is moved or deleted.
     */
    public function up(): void
    {
        foreach (['quotation_lines', 'sales_order_lines', 'invoice_lines'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'analytic_distribution')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->jsonb('analytic_distribution')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['quotation_lines', 'sales_order_lines', 'invoice_lines'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'analytic_distribution')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('analytic_distribution');
                });
            }
        }
    }
};
