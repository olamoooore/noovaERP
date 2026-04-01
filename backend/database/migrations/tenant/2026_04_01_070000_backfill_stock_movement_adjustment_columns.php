<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('stock_movements')) {
            return;
        }

        if (!Schema::hasColumn('stock_movements', 'warehouse_id')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->foreignId('warehouse_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('stock_movements', 'old_quantity')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->integer('old_quantity')->default(0)->after('quantity');
            });
        }

        if (!Schema::hasColumn('stock_movements', 'adjustment_reason_id')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->foreignId('adjustment_reason_id')->nullable()->after('reference_id')->constrained('stock_adjustment_reasons')->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('stock_movements', 'batch_number')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->string('batch_number')->nullable()->after('notes');
            });
        }

        if (!Schema::hasColumn('stock_movements', 'expiry_date')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->date('expiry_date')->nullable()->after('batch_number');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('stock_movements')) {
            return;
        }

        if (Schema::hasColumn('stock_movements', 'expiry_date')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->dropColumn('expiry_date');
            });
        }

        if (Schema::hasColumn('stock_movements', 'batch_number')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->dropColumn('batch_number');
            });
        }

        if (Schema::hasColumn('stock_movements', 'adjustment_reason_id')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->dropForeign(['adjustment_reason_id']);
                $table->dropColumn('adjustment_reason_id');
            });
        }

        if (Schema::hasColumn('stock_movements', 'old_quantity')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->dropColumn('old_quantity');
            });
        }

        if (Schema::hasColumn('stock_movements', 'warehouse_id')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->dropForeign(['warehouse_id']);
                $table->dropColumn('warehouse_id');
            });
        }
    }
};