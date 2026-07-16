<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->enum('category', ['posting', 'reporting', 'system'])->default('posting');
            $table->timestamps();
        });

        // Seed 18 mandatory roles from EDR §8.2
        $roles = [
            ['code' => 'AR_CONTROL', 'name' => 'Accounts Receivable Control', 'description' => 'Main AR control account for customer invoices and receipts', 'is_mandatory' => true, 'category' => 'posting'],
            ['code' => 'AP_CONTROL', 'name' => 'Accounts Payable Control', 'description' => 'Main AP control account for vendor bills and payments', 'is_mandatory' => true, 'category' => 'posting'],
            ['code' => 'CASH_MAIN', 'name' => 'Cash Main', 'description' => 'Primary cash account', 'is_mandatory' => true, 'category' => 'posting'],
            ['code' => 'BANK_MAIN', 'name' => 'Bank Main', 'description' => 'Primary bank account', 'is_mandatory' => true, 'category' => 'posting'],
            ['code' => 'OUTSTANDING_RECEIPTS', 'name' => 'Outstanding Receipts', 'description' => 'Uncleared incoming receipts', 'is_mandatory' => true, 'category' => 'posting'],
            ['code' => 'OUTSTANDING_PAYMENTS', 'name' => 'Outstanding Payments', 'description' => 'Uncleared outgoing payments', 'is_mandatory' => true, 'category' => 'posting'],
            ['code' => 'TAX_INPUT', 'name' => 'Input Tax (GST/VAT)', 'description' => 'Tax paid on purchases', 'is_mandatory' => true, 'category' => 'posting'],
            ['code' => 'TAX_OUTPUT', 'name' => 'Output Tax (GST/VAT)', 'description' => 'Tax collected on sales', 'is_mandatory' => true, 'category' => 'posting'],
            ['code' => 'INVENTORY', 'name' => 'Inventory', 'description' => 'Stock on hand', 'is_mandatory' => true, 'category' => 'posting'],
            ['code' => 'COGS', 'name' => 'Cost of Goods Sold', 'description' => 'Cost of goods sold expense', 'is_mandatory' => true, 'category' => 'reporting'],
            ['code' => 'RETAINED_EARNINGS', 'name' => 'Retained Earnings', 'description' => 'Accumulated retained earnings', 'is_mandatory' => true, 'category' => 'reporting'],
            ['code' => 'FX_GAIN', 'name' => 'Foreign Exchange Gain', 'description' => 'Realized and unrealized FX gains', 'is_mandatory' => true, 'category' => 'posting'],
            ['code' => 'FX_LOSS', 'name' => 'Foreign Exchange Loss', 'description' => 'Realized and unrealized FX losses', 'is_mandatory' => true, 'category' => 'posting'],
            ['code' => 'SUSPENSE', 'name' => 'Suspense Account', 'description' => 'Temporary holding account for unclassified transactions', 'is_mandatory' => true, 'category' => 'system'],
            ['code' => 'ACCRUED_EXPENSES', 'name' => 'Accrued Expenses', 'description' => 'Expenses incurred but not yet paid', 'is_mandatory' => true, 'category' => 'posting'],
            ['code' => 'PREPAID_EXPENSES', 'name' => 'Prepaid Expenses', 'description' => 'Payments made for future expenses', 'is_mandatory' => true, 'category' => 'posting'],
            ['code' => 'INTERCOMPANY_DUE_FROM', 'name' => 'Intercompany Due From', 'description' => 'Amounts owed from related entities', 'is_mandatory' => true, 'category' => 'posting'],
            ['code' => 'INTERCOMPANY_DUE_TO', 'name' => 'Intercompany Due To', 'description' => 'Amounts owed to related entities', 'is_mandatory' => true, 'category' => 'posting'],
        ];

        $now = now();
        foreach ($roles as &$role) {
            $role['created_at'] = $now;
            $role['updated_at'] = $now;
        }

        DB::table('account_roles')->insert($roles);
    }

    public function down(): void
    {
        Schema::dropIfExists('account_roles');
    }
};
