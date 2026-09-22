<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #245: ProductPriceTier.customer_group had no counterpart on Customer,
 * so group-scoped tiers could not be matched (and leaked to everyone via the
 * whereNull('customer_id') branch).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customers') && ! Schema::hasColumn('customers', 'customer_group')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->string('customer_group', 100)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('customers') && Schema::hasColumn('customers', 'customer_group')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('customer_group');
            });
        }
    }
};
