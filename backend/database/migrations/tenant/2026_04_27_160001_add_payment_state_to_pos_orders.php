<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_orders')) {
            return;
        }

        Schema::table('pos_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_orders', 'payment_state')) {
                $table->string('payment_state')->nullable()->after('status')
                    ->comment('Payment state for customer display: processing, completed, failed');
            }

            if (! Schema::hasColumn('pos_orders', 'payment_method')) {
                $table->string('payment_method')->nullable()->after('payment_state');
            }

            if (! Schema::hasColumn('pos_orders', 'amount_tendered')) {
                $table->decimal('amount_tendered', 15, 2)->nullable()->after('payment_method');
            }

            if (! Schema::hasColumn('pos_orders', 'change_due')) {
                $table->decimal('change_due', 15, 2)->nullable()->after('amount_tendered');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_orders')) {
            return;
        }

        Schema::table('pos_orders', function (Blueprint $table) {
            foreach (['payment_state', 'payment_method', 'amount_tendered', 'change_due'] as $column) {
                if (Schema::hasColumn('pos_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

