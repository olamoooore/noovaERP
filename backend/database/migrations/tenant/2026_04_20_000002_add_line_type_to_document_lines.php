<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add line_type and dimension tracking to invoice_lines and bill_lines.
     *
     * line_type distinguishes:
     *   - product: inventory-backed line with product_id
     *   - service: non-inventory service charge
     *   - expense: free-text expense (no product)
     *   - tax: tax line
     *   - discount: discount line
     *
     * gl_account_id: explicit GL account override per line (for accounting-origin flows)
     * Dimension fields: cost_center_id, project_id, department_id, branch_id for allocation
     */
    public function up(): void
    {
        // ── Invoice Lines ────────────────────────────────────
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->string('line_type', 30)->default('product')->after('invoice_id')
                ->comment('product|service|expense|tax|discount');
            $table->foreignId('gl_account_id')->nullable()->after('product_id')
                ->constrained('chart_of_accounts')->nullOnDelete();
            $table->unsignedBigInteger('cost_center_id')->nullable()->after('gl_account_id');
            $table->unsignedBigInteger('project_id')->nullable()->after('cost_center_id');
            $table->unsignedBigInteger('department_id')->nullable()->after('project_id');
            $table->unsignedBigInteger('branch_id')->nullable()->after('department_id');

            $table->index('line_type');
        });

        // ── Bill Lines ───────────────────────────────────────
        Schema::table('bill_lines', function (Blueprint $table) {
            $table->string('line_type', 30)->default('product')->after('bill_id')
                ->comment('product|service|expense|tax|discount');
            $table->unsignedBigInteger('cost_center_id')->nullable()->after('account_id');
            $table->unsignedBigInteger('project_id')->nullable()->after('cost_center_id');
            $table->unsignedBigInteger('department_id')->nullable()->after('project_id');
            $table->unsignedBigInteger('branch_id')->nullable()->after('department_id');

            $table->index('line_type');
        });

        // ── Backfill line_type on existing records ───────────
        // Invoice lines: product lines have product_id, the rest are expenses
        DB::statement("
            UPDATE invoice_lines SET line_type = CASE
                WHEN product_id IS NOT NULL THEN 'product'
                ELSE 'expense'
            END
        ");

        // Bill lines: product lines have product_id, expense lines don't
        DB::statement("
            UPDATE bill_lines SET line_type = CASE
                WHEN product_id IS NOT NULL THEN 'product'
                ELSE 'expense'
            END
        ");
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropIndex(['line_type']);
            $table->dropConstrainedForeignId('gl_account_id');
            $table->dropColumn([
                'line_type', 'cost_center_id', 'project_id', 'department_id', 'branch_id',
            ]);
        });

        Schema::table('bill_lines', function (Blueprint $table) {
            $table->dropIndex(['line_type']);
            $table->dropColumn([
                'line_type', 'cost_center_id', 'project_id', 'department_id', 'branch_id',
            ]);
        });
    }
};
