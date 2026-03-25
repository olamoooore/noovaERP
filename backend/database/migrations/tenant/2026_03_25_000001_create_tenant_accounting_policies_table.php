<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_policies', function (Blueprint $table) {
            $table->id();

            // Depreciation Policy
            $table->string('depreciation_method', 50)->default('straight_line');
            $table->decimal('capitalization_threshold', 15, 2)->default(1000.00);

            // Inventory Valuation Policy
            $table->string('inventory_valuation_method', 50)->default('fifo');

            // Revenue & Expense Recognition
            $table->string('revenue_recognition_policy', 50)->default('accrual');
            $table->string('expense_recognition_policy', 50)->default('matching');

            // Other Policies
            $table->string('bad_debt_policy', 50)->default('allowance_method');
            $table->text('notes')->nullable();

            $table->timestamps();
        });

        // Seed default row
        DB::table('accounting_policies')->insert([
            'depreciation_method' => 'straight_line',
            'capitalization_threshold' => 1000.00,
            'inventory_valuation_method' => 'fifo',
            'revenue_recognition_policy' => 'accrual',
            'expense_recognition_policy' => 'matching',
            'bad_debt_policy' => 'allowance_method',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_policies');
    }
};
