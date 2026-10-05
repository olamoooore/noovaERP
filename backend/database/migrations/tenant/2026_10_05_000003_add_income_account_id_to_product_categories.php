<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Issue #887: the Odoo income-account chain is product → product category
     * → document default. `products.income_account_id` existed, but a product
     * with no account of its own could not fall back to its category's account
     * because the column did not exist on product_categories.
     */
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->foreignId('income_account_id')->nullable()->after('parent_id')
                ->constrained('chart_of_accounts');
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('income_account_id');
        });
    }
};
