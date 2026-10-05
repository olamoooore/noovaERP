<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Issue #884 (UX2): the optional line income account and analytic
     * dimensions must flow quotation → sales order → invoice.
     *
     * sales_order_lines already carried income_account_id; quotation_lines had
     * no column for either the account or the dimensions, and sales_order_lines
     * had no dimension columns, so the accepted quote's values had nowhere to
     * land on the way to the invoice (which already has gl_account_id,
     * project_id and department_id).
     */
    public function up(): void
    {
        Schema::table('quotation_lines', function (Blueprint $table) {
            $table->foreignId('income_account_id')->nullable()->after('product_id')
                ->constrained('chart_of_accounts')->nullOnDelete();
            $table->unsignedBigInteger('project_id')->nullable()->after('income_account_id');
            $table->unsignedBigInteger('department_id')->nullable()->after('project_id');
        });

        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable()->after('income_account_id');
            $table->unsignedBigInteger('department_id')->nullable()->after('project_id');
        });
    }

    public function down(): void
    {
        Schema::table('quotation_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('income_account_id');
            $table->dropColumn(['project_id', 'department_id']);
        });

        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->dropColumn(['project_id', 'department_id']);
        });
    }
};
